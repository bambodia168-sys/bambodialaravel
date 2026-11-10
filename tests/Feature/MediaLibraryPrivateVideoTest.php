<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Media\PrivateVideoStreamer;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

final class MediaLibraryPrivateVideoTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role='freelancer'): User
    {
        return User::query()->create([
            'name'=>'Media Video '.$role,
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'MediaVideoStrongPassword123!',
            'role'=>$role,
        ]);
    }

    private function makeVideo(User $owner, string $disk='local'): MediaAsset
    {
        $storagePath='marketplace/previews/'.$owner->id.'/video-example.mp4';
        return MediaAsset::query()->create([
            'user_id'=>$owner->id,
            'original_name'=>'private-video.mp4',
            'storage_path'=>$storagePath,
            'storage_disk'=>$disk,
            'mime_type'=>'video/mp4',
            'bytes'=>2048,
            'width'=>0,
            'height'=>0,
            'alt_text'=>'A short demonstration video for the Media Library',
            'rights_basis'=>'owned',
            'rights_holder'=>'Original video owner',
            'review_status'=>'pending',
        ]);
    }

    public function test_range_parser_supports_seek_suffix_and_bounded_segments(): void
    {
        $this->assertSame([0,1023],PrivateVideoStreamer::parseRange('bytes=0-1023',8192));
        $this->assertSame([8192-500,8191],PrivateVideoStreamer::parseRange('bytes=-500',8192));
        $this->assertSame([2048,8191],PrivateVideoStreamer::parseRange('bytes=2048-',8192));
        $this->assertSame([0,8191],PrivateVideoStreamer::parseRange(null,8192));
        $this->assertSame([0,1024*1024-1],
            PrivateVideoStreamer::parseRange('bytes=0-',12*1024*1024));
        foreach (['bytes=9-2','bytes=8192-','bytes=','bytes=0-1,10-11','items=0-2','bytes=-0'] as $invalid) {
            $this->assertNull(PrivateVideoStreamer::parseRange($invalid,8192));
        }
    }

    public function test_authorized_owner_and_admin_get_local_video_and_seek_without_public_exposure(): void
    {
        Storage::fake('local');
        $owner=$this->member();
        $other=$this->member();
        $admin=$this->member('admin');
        $asset=$this->makeVideo($owner);
        Storage::disk('local')->put($asset->storage_path,'0123456789abcdefghijklmnopqrstuv');

        $this->get($asset->previewUrl())->assertNotFound();
        $this->actingAs($other)->get($asset->previewUrl())->assertNotFound();

        $preview=$this->actingAs($owner)->get($asset->previewUrl());
        $preview->assertOk()->assertHeader('Content-Type','video/mp4')
            ->assertHeader('X-Content-Type-Options','nosniff');
        $cacheControl=$preview->headers->get('Cache-Control','');
        $this->assertStringContainsString('private',$cacheControl);
        $this->assertStringContainsString('no-store',$cacheControl);
        $this->assertStringNotContainsString('public',$cacheControl);

        $this->withHeaders(['Range'=>'bytes=4-8'])->get($asset->previewUrl())
            ->assertStatus(206)
            ->assertHeader('Content-Range','bytes 4-8/32');
        $this->withHeaders(['Range'=>'bytes=0-0'])->head($asset->previewUrl())
            ->assertStatus(206);

        $this->actingAs($admin)->get($asset->previewUrl())->assertOk();
    }

    public function test_media_table_displays_a_playable_thumbnail_and_clean_player_in_original_dialog(): void
    {
        $owner=$this->member('super_admin');
        $asset=$this->makeVideo($owner);
        $this->actingAs($owner)->get(route('dashboard.media.index'))
            ->assertOk()
            ->assertSee('Preview video: private-video.mp4')
            ->assertSee('data-media-video-thumb',false)
            ->assertSee('data-media-player',false)
            ->assertSee('data-player-seek',false)
            ->assertSee('data-player-fullscreen',false)
            ->assertSee('media-library-video.css?v=1',false)
            ->assertSee('media-library-video.js?v=1',false)
            ->assertSee('Accessible video description')
            ->assertSee($asset->previewUrl(),false)
            ->assertDontSee('r2.cloudflarestorage.com');

        $customer=$this->member('customer');
        $this->actingAs($customer)->get(route('account.media.index'))
            ->assertOk()->assertDontSee('private-video.mp4');
    }

    public function test_r2_get_is_authorized_byte_range_stream_without_publishing_cloud_url(): void
    {
        $asset=$this->makeVideo($this->member(),'r2');
        $payload='0123456789abcdefghijklmnopqrstuv';

        $client=Mockery::mock();
        $client->shouldReceive('getObject')
            ->once()
            ->withArgs(fn (array $params) =>
                $params['Bucket']==='private-marketplace'
                && $params['Key']===$asset->storage_path
                && $params['Range']==='bytes=4-11')
            ->andReturn(['Body'=>Utils::streamFor(substr($payload,4,8))]);

        $disk=Mockery::mock();
        $disk->shouldReceive('size')->once()->with($asset->storage_path)->andReturn(strlen($payload));
        $disk->shouldReceive('getClient')->once()->andReturn($client);
        config()->set('filesystems.disks.r2.bucket','private-marketplace');

        $request=Request::create('/media/'.$asset->public_id.'/preview','GET',[],[],[],[
            'HTTP_RANGE'=>'bytes=4-11',
        ]);
        $response=PrivateVideoStreamer::serve($request,$asset,$disk,[
            'Content-Type'=>'video/mp4',
            'Cache-Control'=>'private, no-store, max-age=0',
        ]);
        $this->assertSame(206,$response->getStatusCode());
        $this->assertSame('bytes 4-11/32',$response->headers->get('Content-Range'));
        $this->assertSame('bytes',$response->headers->get('Accept-Ranges'));
        $this->assertSame('8',$response->headers->get('Content-Length'));
        $this->assertStringNotContainsString('cloudflarestorage',(string) json_encode($response->headers->all()));

        ob_start();
        $response->sendContent();
        $this->assertSame('456789ab',ob_get_clean());
    }

    public function test_r2_invalid_range_returns_416_without_s3_read(): void
    {
        $asset=$this->makeVideo($this->member(),'r2');
        $disk=Mockery::mock();
        $disk->shouldReceive('size')->once()->andReturn(32);
        $disk->shouldNotReceive('getClient');

        $request=Request::create('/media/'.$asset->public_id.'/preview','GET',[],[],[],[
            'HTTP_RANGE'=>'bytes=99-',
        ]);
        $response=PrivateVideoStreamer::serve($request,$asset,$disk,[
            'Content-Type'=>'video/mp4',
        ]);
        $this->assertSame(416,$response->getStatusCode());
        $this->assertSame('bytes */32',$response->headers->get('Content-Range'));
    }

    public function test_r2_head_does_not_read_video_contents(): void
    {
        $asset=$this->makeVideo($this->member(),'r2');
        $disk=Mockery::mock();
        $disk->shouldReceive('size')->once()->andReturn(32);
        $disk->shouldNotReceive('getClient');
        $request=Request::create('/media/'.$asset->public_id.'/preview','HEAD',[],[],[],[
            'HTTP_RANGE'=>'bytes=0-7',
        ]);
        $response=PrivateVideoStreamer::serve($request,$asset,$disk,[
            'Content-Type'=>'video/mp4',
        ]);
        $this->assertSame(206,$response->getStatusCode());
        $this->assertSame('8',$response->headers->get('Content-Length'));
        $this->assertSame('bytes 0-7/32',$response->headers->get('Content-Range'));
    }
}
