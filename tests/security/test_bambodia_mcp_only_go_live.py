"""Production MCP activation guard synthetic tests: no live host interaction."""
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
from unittest import TestCase
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
SCRIPT=ROOT/"support/bambodia-mcp-readonly-go-live-20261114.py"
SPEC=importlib.util.spec_from_file_location("bambodia_mcp_only_activation",SCRIPT)
gate=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(gate)


class McpReadOnlyActivationTests(TestCase):
    def test_switch_render_keeps_other_settings_and_disables_writes(self):
        source=(
            "APP_NAME=KeepOriginal\n"
            "BAMBODIA_MCP_ENABLED='false'\n"
            "BAMBODIA_MCP_WRITES_ENABLED=false\n"
            "CUSTOM_SETTING=KeepOriginal\n"
        ).encode()
        changed=gate.render_env(source).decode()
        self.assertIn("APP_NAME=KeepOriginal",changed)
        self.assertIn("CUSTOM_SETTING=KeepOriginal",changed)
        self.assertIn("BAMBODIA_MCP_ENABLED=true\n",changed)
        self.assertIn("BAMBODIA_MCP_WRITES_ENABLED=false\n",changed)
        self.assertEqual(changed.count("BAMBODIA_MCP_ENABLED="),1)

    def test_append_off_flag_instead_of_implicit_write_enable(self):
        source=b"APP_ENV=production\n"
        changed=gate.render_env(source)
        self.assertIn(b"BAMBODIA_MCP_ENABLED=true\n",changed)
        self.assertIn(b"BAMBODIA_MCP_WRITES_ENABLED=false\n",changed)

    def test_duplicate_switch_rejected(self):
        with self.assertRaises(gate.Blocked):
            gate.render_env(b"BAMBODIA_MCP_ENABLED=false\nBAMBODIA_MCP_ENABLED=false\n")

    def test_only_exact_approved_chatgpt_redirect_accepted(self):
        self.assertTrue(gate.CALLBACK.fullmatch(
            "https://chatgpt.com/connector/oauth/abc123_DEF"))
        self.assertFalse(gate.CALLBACK.fullmatch(
            "https://chatgpt.com.evil.invalid/connector/oauth/abc123_DEF"))
        self.assertFalse(gate.CALLBACK.fullmatch(
            "https://chatgpt.com/connector/oauth/abc123_DEF?next=https://evil.invalid"))
        self.assertFalse(gate.CALLBACK.fullmatch("https://chatgpt.com/connector/oauth"))

    def test_env_rejects_write_enabled_and_missing_callback(self):
        with tempfile.TemporaryDirectory() as dirname:
            env=Path(dirname)/".env"
            env.write_text(
                "BAMBODIA_MCP_ENABLED=false\n"
                "BAMBODIA_MCP_WRITES_ENABLED=true\n"
                "BAMBODIA_MCP_ALLOWED_CALLBACK_URIS=https://chatgpt.com/connector/oauth/abc123_DEF\n"
            )
            env.chmod(0o600)
            with patch.object(gate,"ENV",env):
                with self.assertRaises(gate.Blocked):
                    gate.env_read()
                env.write_text("BAMBODIA_MCP_ENABLED=false\n")
                with self.assertRaises(gate.Blocked):
                    gate.env_read()

    def test_env_rejects_world_readable(self):
        with tempfile.TemporaryDirectory() as dirname:
            env=Path(dirname)/".env"
            env.write_text(
                "BAMBODIA_MCP_ENABLED=false\n"
                "BAMBODIA_MCP_WRITES_ENABLED=false\n"
                "BAMBODIA_MCP_ALLOWED_CALLBACK_URIS=https://chatgpt.com/connector/oauth/abc123_DEF\n"
            )
            env.chmod(0o644)
            with patch.object(gate,"ENV",env):
                with self.assertRaises(gate.Blocked):
                    gate.env_read()

    def test_no_write_on_incomplete_runtime(self):
        with patch.object(gate,"inspect",return_value=False):
            with patch.object(gate,"update_env") as update:
                with patch("sys.argv",["mcp","--enable-readonly"]):
                    with patch.object(gate.os,"geteuid",return_value=0):
                        self.assertEqual(gate.main(),2)
                update.assert_not_called()

    def test_source_bans_schema_mutations_deploy_and_backup(self):
        source=SCRIPT.read_text()
        for prohibited in (
            "artisan migrate --force","composer install","restic backup",
            "BAMBODIA_MCP_WRITES_ENABLED=true","os.system(",
            "shell=True", "php artisan passport:keys",
        ):
            self.assertNotIn(prohibited,source)
