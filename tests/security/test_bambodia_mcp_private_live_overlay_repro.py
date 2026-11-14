"""Private failed-cutover reproduction: synthetic, no production mutation."""
import importlib.util
import json
from pathlib import Path
import tempfile
from unittest import TestCase
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/"support/bambodia-mcp-private-live-overlay-repro-20261114.py"
SPEC=importlib.util.spec_from_file_location("mcp_private_overlay_repro",SRC)
m=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)

class PrivateOverlayCutoverReproTests(TestCase):
    def test_source_changed_allowlist_is_mcp_only(self):
        self.assertEqual(len(m.CHANGED),10)
        self.assertIn("bootstrap/app.php",m.CHANGED)
        self.assertIn("routes/web.php",m.CHANGED)
        self.assertFalse(any("backup" in p or "r2" in p or "public/build" in p
                             for p in m.CHANGED))

    def test_no_live_writes_database_scripts_or_copied_credentials(self):
        s=SRC.read_text()
        for pattern in (
            "artisan migrate", "passport:keys", "composer install",
            "composer update", "restic", "os.system(", "shell=True",
            "BAMBODIA_MCP_ENABLED=true", "BAMBODIA_MCP_WRITES_ENABLED=true",
            'copytree(LIVE,"storage"', 'copytree(LIVE,"vendor"',
            "shutil.rmtree(LIVE)", "os.replace(LIVE",
        ):
            self.assertNotIn(pattern,s)

    def test_error_classifier_never_displays_raw_exception_content(self):
        self.assertEqual(m.sanitize_php_error(
            b"SQLSTATE[42S02] unknown table credentials SECRET123"),
            "DB_SCHEMA_OR_CONNECTION")
        self.assertEqual(m.sanitize_php_error(
            b"Fatal error: Call to undefined method Foo::token() PRIVATE"),
            "API_COMPATIBILITY")
        self.assertEqual(m.sanitize_php_error(b"PRIVATE KEY=secret"),"NONDISCLOSING_PHP_FAILURE")

    def test_oauth_routes_and_mcp_are_required(self):
        good=[{"uri":p,"method":method} for p,method in m.EXPECT]
        self.assertTrue(m.validate_routes(good))
        self.assertFalse(m.validate_routes(good[:-1]))
        with self.assertRaises(m.Stop):
            m.validate_routes({})

    def test_symlink_and_parent_traversal_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory)
            (base/"safe").mkdir()
            (base/"safe/file.php").write_text("<?php")
            self.assertEqual(m.read(base,"safe/file.php"),b"<?php")
            (base/"link").symlink_to(base/"safe",target_is_directory=True)
            with self.assertRaises(m.Stop):
                m.read(base,"link/file.php")
            with self.assertRaises(m.Stop):
                m.read(base,"../etc/passwd")

    def test_private_reproduction_denies_nonroot(self):
        with patch.object(m.os,"geteuid",return_value=1000):
            with self.assertRaises(m.Stop):
                m.checked_originals()

    def test_synthetic_env_forces_testing_and_false_mcp_flags(self):
        s=SRC.read_text()
        self.assertIn('"APP_ENV":"testing"',s)
        self.assertIn('"DB_CONNECTION":"sqlite"',s)
        self.assertIn('"DB_DATABASE":":memory:"',s)
        self.assertIn('"BAMBODIA_MCP_ENABLED":"false"',s)
        self.assertIn('"BAMBODIA_MCP_WRITES_ENABLED":"false"',s)
        self.assertNotIn('".env",temp',s)

    def test_synthetic_clone_permissions_use_os_chown_not_path_chown(self):
        from types import SimpleNamespace
        import stat
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)/"clone"
            source=root/"app/Example.php"
            source.parent.mkdir(parents=True)
            source.write_text("<?php",encoding="utf-8")
            with patch.object(m.os,"chown") as chown, \
                 patch("pwd.getpwnam",return_value=SimpleNamespace(pw_gid=1234)):
                m.normalize_code(root)
            self.assertEqual(stat.S_IMODE(source.stat().st_mode),0o640)
            self.assertEqual(stat.S_IMODE(source.parent.stat().st_mode),0o750)
            chown.assert_any_call(root,0,1234)
            chown.assert_any_call(source,0,1234)
        self.assertNotIn(".chown(",SRC.read_text().replace("os.chown(",""))

    def test_unexpected_attribute_error_is_safe_and_no_traceback_leaks(self):
        import io
        from contextlib import redirect_stdout
        with patch.object(m,"clone_runtime",side_effect=AttributeError("secret detail")), \
             patch.object(m.sys,"argv",["test","--reproduce"]):
            buffer=io.StringIO()
            with redirect_stdout(buffer):
                outcome=m.main()
        self.assertEqual(outcome,1)
        self.assertIn("MCP_REPRODUCTION_BLOCKER=PRIVATE_REVIEW_REQUIRED",buffer.getvalue())
        self.assertNotIn("secret detail",buffer.getvalue())


    def test_private_php_binary_launch_passes_without_emitting_secrets(self):
        from types import SimpleNamespace
        fake=SimpleNamespace(
            returncode=0,pid=101,
            communicate=lambda timeout:(b"PHP85_OK",b"")
        )
        with patch.object(m.subprocess,"Popen",return_value=fake) as opened:
            got=m.run_ww_php(Path("/var/tmp/fixture"),{"APP_ENV":"testing"},
                             ["-r","echo 'PHP85_OK';"],15,"PHP_BINARY")
        self.assertEqual(got,b"PHP85_OK")
        kwargs=opened.call_args.kwargs
        self.assertTrue(kwargs["start_new_session"])
        self.assertIs(kwargs["stdin"],m.subprocess.DEVNULL)
        self.assertEqual(kwargs["cwd"],"/var/tmp/fixture")

    def test_php_spawn_error_is_different_from_timeout(self):
        with patch.object(m.subprocess,"Popen",side_effect=FileNotFoundError("secret path")):
            with self.assertRaisesRegex(m.Stop,"PHP85_PHP_BINARY_START_FAILED"):
                m.run_ww_php(Path("/var/tmp/fixture"),{},["-v"],15,"PHP_BINARY")

    def test_private_php_route_timeout_kills_and_reaps_process_group(self):
        from types import SimpleNamespace
        from subprocess import TimeoutExpired
        class FakeProcess:
            pid=987
            def __init__(self):self.calls=0
            def communicate(self,timeout):
                self.calls+=1
                if self.calls==1:raise TimeoutExpired(cmd="test",timeout=timeout)
                return (b"",b"")
        fake=FakeProcess()
        with patch.object(m.subprocess,"Popen",return_value=fake), \
             patch.object(m.os,"killpg") as killed:
            with self.assertRaisesRegex(m.Stop,"PHP85_ARTISAN_ROUTE_TIMEOUT_60S"):
                m.run_ww_php(Path("/var/tmp/fixture"),{},
                             ["artisan","route:list"],60,"ARTISAN_ROUTE")
        killed.assert_called_once_with(987,m.signal.SIGKILL)
        self.assertEqual(fake.calls,2)

    def test_private_php_nonzero_is_classified_not_echoed(self):
        from types import SimpleNamespace
        fake=SimpleNamespace(returncode=1,pid=100,
            communicate=lambda timeout:(b"",b"SQLSTATE[42S02] credentials PRIVATE"))
        with patch.object(m.subprocess,"Popen",return_value=fake):
            with self.assertRaisesRegex(m.Stop,"PHP85_ARTISAN_ROUTE_DB_SCHEMA_OR_CONNECTION"):
                m.run_ww_php(Path("/var/tmp/fixture"),{},["artisan"],60,"ARTISAN_ROUTE")

    def test_only_known_phase_identifiers_allowed(self):
        with self.assertRaisesRegex(m.Stop,"UNSAFE_EXECUTION_PHASE"):
            m.run_ww_php(Path("/var/tmp/fixture"),{},["-v"],15,"SECRET_VALUE")

    def test_absolute_runuser_works_with_sanitized_path(self):
        result=m.safe_runuser_binary()
        self.assertIn(result,("/usr/sbin/runuser","/usr/bin/runuser"))
        self.assertTrue(Path(result).is_file())
        self.assertNotEqual(result,"runuser")

    def test_php_process_launch_uses_explicit_runuser_executable(self):
        from types import SimpleNamespace
        fake=SimpleNamespace(returncode=0,pid=101,
                             communicate=lambda timeout:(b"PHP85_OK",b""))
        with patch.object(m,"safe_runuser_binary",return_value="/usr/sbin/runuser"), \
             patch.object(m.subprocess,"Popen",return_value=fake) as opened:
            m.run_ww_php(Path("/var/tmp/fixture"),{"PATH":"/usr/bin:/bin"},
                         ["-r","echo 'PHP85_OK';"],15,"PHP_BINARY")
        self.assertEqual(opened.call_args.args[0][0],"/usr/sbin/runuser")
