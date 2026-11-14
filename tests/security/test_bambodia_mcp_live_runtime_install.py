"""MCP-only production installer: synthetic fail-closed safety contract."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
from unittest import TestCase
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
FILE=ROOT/"support/bambodia-mcp-live-runtime-install-20261114.py"
spec=importlib.util.spec_from_file_location("bambodia_mcp_only_installer",FILE)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class McpOnlyInstallerSecurityTest(TestCase):
    def test_exact_existing_modified_files(self):
        self.assertEqual(len(m.EXISTING_CHANGED),10)
        self.assertEqual(len(m.CRITICAL),9)
        self.assertIn("bootstrap/app.php",m.EXISTING_CHANGED)
        self.assertIn("routes/web.php",m.CRITICAL)
        self.assertFalse(any("recovery" in p.lower() or "r2" in p.lower()
                             for p in m.EXISTING_CHANGED))

    def test_invalid_relative_path_blocks(self):
        for path in ("../x.php", "/etc/passwd", "a/../b", "\x00secret", "",
                     "./composer.lock"):
            self.assertFalse(m.good_relative(path),repr(path))
        self.assertTrue(m.good_relative("app/Models/User.php"))

    def test_broken_candidate_identity_blocks_unsafe_vendor_swap(self):
        with patch.object(m.os,"geteuid",return_value=1000):
            with self.assertRaises(m.Stop):
                m.safe_patch_inventory()

    def test_no_secret_flag_or_database_operations(self):
        s=FILE.read_text()
        for forbidden in (
            "BAMBODIA_MCP_ENABLED=true",
            "BAMBODIA_MCP_WRITES_ENABLED=true",
            'artisan migrate', 'passport:keys',
            'composer install','composer update', 'restic',
            "os.system(", "shell=True", "migrate:fresh", 
        ):
            self.assertNotIn(forbidden,s)

    def test_lock_compare_helpers_are_exact(self):
        old=b'{"packages":[{"name":"laravel/framework","version":"v13.40.0"}],"packages-dev":[]}'
        new=b'{"packages":[{"name":"laravel/framework","version":"v13.42.0"}],"packages-dev":[]}'
        self.assertEqual(m.lock_versions(old),{"laravel/framework":"v13.40.0"})
        self.assertEqual(m.lock_versions(new),{"laravel/framework":"v13.42.0"})

    def test_env_flag_guard_preserves_secrets_and_blocks_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            f=root/".env"
            f.write_text("APP_KEY=private-unchanged\nBAMBODIA_MCP_ENABLED=false\nBAMBODIA_MCP_WRITES_ENABLED=false\n")
            with patch.object(m,"LIVE",root):
                m.env_flag_off()
                self.assertIn("APP_KEY=private-unchanged",f.read_text())
                f.write_text("BAMBODIA_MCP_ENABLED=true\n")
                with self.assertRaises(m.Stop):
                    m.env_flag_off()
                f.write_text("BAMBODIA_MCP_WRITES_ENABLED=true\n")
                with self.assertRaises(m.Stop):
                    m.env_flag_off()

    def test_explicit_rollback_does_not_allow_arbitrary_root_path(self):
        with tempfile.TemporaryDirectory() as dirname:
            with self.assertRaises(m.Stop):
                m.manual_rollback(Path(dirname))

    def test_vendor_root_must_be_private_for_source_stage(self):
        import stat
        from types import SimpleNamespace
        with patch.object(m.Path,"lstat",autospec=True,
                return_value=SimpleNamespace(st_mode=stat.S_IFDIR|0o755,st_uid=0)):
            self.assertFalse(m.private_dir(m.STAGE))

    def test_new_laravel_directories_remain_www_readable_with_strict_root_umask(self):
        with tempfile.TemporaryDirectory() as directory:
            live=Path(directory)/"application"
            live.mkdir(mode=0o700)
            dest=live/"app/Http/Controllers/Developer/NewController.php"
            with patch.object(m,"LIVE",live):
                old=os.umask(0o077)
                try:
                    m.safe_live_parent(dest)
                finally:
                    os.umask(old)
            for path in (live/"app",live/"app/Http",live/"app/Http/Controllers",
                         live/"app/Http/Controllers/Developer"):
                self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o755)

    def test_parent_symlink_never_followed(self):
        with tempfile.TemporaryDirectory() as directory:
            live=Path(directory)/"application"
            live.mkdir()
            (live/"app").symlink_to(Path(directory),target_is_directory=True)
            with patch.object(m,"LIVE",live):
                with self.assertRaises(m.Stop):
                    m.safe_live_parent(live/"app/Controls/file.php")

    def test_rollback_preserves_existing_source(self):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory)
            live=base/"app"
            backup=base/"backup"
            live.mkdir(); (backup/"original").mkdir(parents=True)
            original=live/"routes/web.php"
            original.parent.mkdir()
            original.write_bytes(b"OLD ROUTES\n")
            copy=backup/"original/routes/web.php"
            copy.parent.mkdir(parents=True)
            copy.write_bytes(b"OLD ROUTES\n")
            oldvend=live/".mcp-previous-vendor"
            (oldvend/"composer").mkdir(parents=True)
            (oldvend/"autoload.php").write_text("old")
            newvend=live/".mcp-next-vendor"
            vendor=live/"vendor"
            vendor.mkdir()
            (vendor/"autoload.php").write_text("installed")
            original.write_bytes(b"NEW ROUTES\n")
            entry={"routes/web.php":{
                "exists":True, "mode":0o644, "uid":os.geteuid(),
                "gid":os.getegid(), "sha":m.sha(b"OLD ROUTES\n")}}
            with patch.object(m,"LIVE",live):
                m.rollback(backup,entry,oldvend,newvend)
            self.assertEqual(original.read_bytes(),b"OLD ROUTES\n")
            self.assertEqual((vendor/"autoload.php").read_text(),"old")
            self.assertFalse(newvend.exists())
