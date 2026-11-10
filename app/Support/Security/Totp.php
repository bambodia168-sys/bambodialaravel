<?php

namespace App\Support\Security;

final class Totp
{
    private const ALPHABET='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function createSecret(int $bytes=20): string
    {
        $bits='';
        foreach (unpack('C*',random_bytes($bytes)) as $byte) {
            $bits.=str_pad(decbin($byte),8,'0',STR_PAD_LEFT);
        }
        $output='';
        foreach (str_split($bits,5) as $group) {
            $output.=self::ALPHABET[bindec(str_pad($group,5,'0',STR_PAD_RIGHT))];
        }
        return $output;
    }

    public static function code(string $base32, int $counter): string
    {
        $binary='';
        $bits='';
        foreach (str_split(strtoupper($base32)) as $char) {
            $position=strpos(self::ALPHABET,$char);
            if ($position===false) {
                throw new \InvalidArgumentException('Malformed authenticator secret.');
            }
            $bits.=str_pad(decbin($position),5,'0',STR_PAD_LEFT);
        }
        foreach (str_split(substr($bits,0,strlen($bits)-strlen($bits)%8),8) as $group) {
            $binary.=chr(bindec($group));
        }
        $hash=hash_hmac('sha1',pack('N2',0,$counter),$binary,true);
        $offset=ord($hash[strlen($hash)-1]) & 15;
        $number=unpack('N',substr($hash,$offset,4))[1] & 0x7fffffff;
        return str_pad((string)($number%1000000),6,'0',STR_PAD_LEFT);
    }

    public static function matchingCounter(string $secret, string $input, ?int $lastCounter=null): ?int
    {
        if (! preg_match('/\A[0-9]{6}\z/D',$input)) {
            return null;
        }
        $current=intdiv(now()->timestamp,30);
        foreach ([$current-1,$current,$current+1] as $counter) {
            if ($counter<0 || ($lastCounter!==null && $counter<=$lastCounter)) {
                continue;
            }
            if (hash_equals(self::code($secret,$counter),$input)) {
                return $counter;
            }
        }
        return null;
    }

    public static function recoveryCodes(): array
    {
        return array_map(
            fn () => strtoupper(bin2hex(random_bytes(5))),
            range(1,8)
        );
    }

    public static function uri(string $issuer, string $email, string $secret): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$email)
            .'?secret='.rawurlencode($secret).'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits=6&period=30';
    }
}
