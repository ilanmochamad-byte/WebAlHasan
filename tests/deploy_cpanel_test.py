"""Uji deployment hanya pada repositori/folder sintetis di direktori sementara."""

import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest


SCRIPT = (Path(__file__).resolve().parents[1] / "bin/deploy-cpanel.sh").read_text()
GIT = shutil.which("git")


class DeployCpanelTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="deploy-cpanel-test-")
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name).resolve()
        self.source = self.base / "repo"
        self.target = self.base / "public_html"
        self.source.mkdir()
        self.target.mkdir()
        self.tools = self.base / "tools"
        self.tools.mkdir()
        # Tidak ada rsync di PATH yang dipakai skrip.
        for tool in ("git", "cmp", "cp", "mkdir", "mktemp", "rm"):
            (self.tools / tool).symlink_to(shutil.which(tool))

        self.files = {
            "index.php": "new-index",
            "koneksi.php": "new-connection",
            "admin/admin_izin.php": "new-admin",
            "api/v1/index.php": "new-api",
            "app/Service.php": "new-service",
            "assets/ui/style.css": "new-css",
            "bin/worker.php": "new-worker",
            "database/migrations/001.sql": "schema-code",
            "portal/a space.php": "new-space",
            "portal/a\nnewline.php": "new-newline",
            "composer.json": "{}",
            "composer.lock": "{}",
            ".env": "repo-env",
            ".htaccess": "repo-htaccess",
            ".user.ini": "repo-ini",
            "php.ini": "repo-php-ini",
            "storage/private/rapor.pdf": "repo-pdf",
            "berkas_psb/doc.jpg": "repo-psb",
            "gambar/pic.jpg": "repo-image",
            "gambar_galeri/pic.jpg": "repo-gallery",
            "smkt.alhasan.co.id/index.php": "repo-subdomain",
            "error_log": "repo-log",
            "admin/error_log": "repo-admin-log",
            "admin/debug.log": "repo-debug-log",
            "admin/.env.local": "repo-admin-env",
            "admin/.git/config": "repo-nested-git",
            "admin/file_download/user.pdf": "repo-download",
            "portal/uploads/user.jpg": "repo-upload",
            "docs/guide.md": "repo-docs",
            "tests/test.php": "repo-tests",
            "backup.sql": "repo-dump",
        }
        for name, value in self.files.items():
            self.write(self.source, name, value)
            self.write(self.target, name, "hosting-" + value)
        self.write(self.target, "admin/local-only.php", "hosting-only")
        self.write(self.target, "alhasan/index.php", "hosting-other-site")
        self.write(self.target, "file_download/local.pdf", "hosting-download")
        os.chmod(self.target / ".env", 0o600)
        script = SCRIPT.replace(
            'SOURCE="/DATA/k1807225/repositories/WebAlHasan"',
            f'SOURCE="{self.source}"',
        ).replace(
            'TARGET="/DATA/k1807225/public_html"', f'TARGET="{self.target}"'
        )
        self.write(self.source, "bin/deploy-cpanel.sh", script)
        self.git("init", "-b", "main")
        self.git("add", ".")
        self.git("commit", "-qm", "synthetic fixture")
        self.before = self.snapshot()

    @staticmethod
    def write(root, name, content):
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)

    def git(self, *args):
        return subprocess.run(
            [GIT, "-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid",
             "-c", "commit.gpgsign=false", "-c", "core.hooksPath=/dev/null", *args],
            cwd=self.source, check=True, capture_output=True, text=True,
        )

    def run_script(self, *args):
        env = dict(os.environ, PATH=str(self.tools), TMPDIR=str(self.base))
        return subprocess.run(
            ["/bin/bash", str(self.source / "bin/deploy-cpanel.sh"), *args],
            cwd=self.base, env=env, capture_output=True, text=True,
        )

    def snapshot(self):
        result = {}
        for path in self.target.rglob("*"):
            mode = stat.S_IMODE(path.lstat().st_mode)
            content = (
                ("link", os.readlink(path)) if path.is_symlink()
                else ("dir",) if path.is_dir()
                else ("file", path.read_bytes())
            )
            result[str(path.relative_to(self.target))] = (mode, content)
        return result

    def assert_rejected_without_writes(self, *args):
        before = self.snapshot()
        result = self.run_script(*args)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertEqual(self.snapshot(), before)
        return result

    def test_dry_run_has_no_target_writes_without_rsync(self):
        # Direktori baru pun tidak boleh dibuat saat simulasi.
        self.write(self.source, "portal/new/deep.php", "new-directory")
        self.git("add", ".")
        self.git("commit", "-qm", "new file")
        result = self.run_script("--dry-run")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("SIMULASI", result.stdout)
        self.assertIn("UPDATE index.php", result.stdout)
        self.assertIn("portal/new/deep.php", result.stdout)
        self.assertEqual(self.snapshot(), self.before)

    def test_copy_preserves_runtime_other_sites_and_host_only_files(self):
        self.write(self.source, "portal/new/deep.php", "new-directory")
        self.git("add", ".")
        self.git("commit", "-qm", "new file")
        os.chmod(self.target / "index.php", 0o640)
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stderr)
        allowed = {
            "index.php", "koneksi.php", "admin/admin_izin.php", "api/v1/index.php",
            "app/Service.php", "assets/ui/style.css", "bin/worker.php",
            "database/migrations/001.sql", "portal/a space.php", "portal/a\nnewline.php",
            "composer.json", "composer.lock",
        }
        for name, value in self.files.items():
            expected = value if name in allowed else "hosting-" + value
            self.assertEqual((self.target / name).read_text(), expected, name)
            if name not in allowed:
                self.assertEqual(self.snapshot()[name], self.before[name], name)
        self.assertEqual((self.target / "admin/local-only.php").read_text(), "hosting-only")
        self.assertEqual((self.target / "alhasan/index.php").read_text(), "hosting-other-site")
        self.assertEqual((self.target / "file_download/local.pdf").read_text(), "hosting-download")
        self.assertEqual((self.target / "portal/new/deep.php").read_text(), "new-directory")
        self.assertEqual(stat.S_IMODE((self.target / "index.php").stat().st_mode), 0o640)
        repeat = self.run_script("--dry-run")
        self.assertEqual(repeat.returncode, 0, repeat.stderr)
        self.assertIn("0 file berbeda", repeat.stdout)

    def test_unknown_options_never_trigger_deploy(self):
        for args in (("--dryrun",), ("--delete",), ("--dry-run", "--delete")):
            self.assert_rejected_without_writes(*args)

    def test_feature_branch_is_rejected(self):
        self.git("switch", "-c", "feature")
        result = self.assert_rejected_without_writes()
        self.assertIn("Branch sumber harus main", result.stderr)

    def test_dirty_and_untracked_source_are_rejected(self):
        self.write(self.source, "local-only.php", "untracked")
        self.assert_rejected_without_writes()
        (self.source / "local-only.php").unlink()
        self.write(self.source, "index.php", "dirty")
        self.assert_rejected_without_writes()

    def test_missing_env_is_rejected(self):
        (self.target / ".env").unlink()
        result = self.assert_rejected_without_writes()
        self.assertIn(".env produksi", result.stderr)

    def test_destination_file_symlink_is_rejected(self):
        outside = self.base / "outside.php"
        outside.write_text("outside")
        (self.target / "index.php").unlink()
        (self.target / "index.php").symlink_to(outside)
        self.assert_rejected_without_writes()
        self.assertEqual(outside.read_text(), "outside")

    def test_destination_parent_symlink_is_rejected(self):
        outside = self.base / "outside"
        outside.mkdir()
        shutil.rmtree(self.target / "portal")
        (self.target / "portal").symlink_to(outside, target_is_directory=True)
        self.assert_rejected_without_writes()
        self.assertEqual(list(outside.iterdir()), [])

    def test_source_symlink_is_rejected(self):
        outside = self.base / "outside.php"
        outside.write_text("outside")
        (self.source / "index.php").unlink()
        (self.source / "index.php").symlink_to(outside)
        self.git("add", ".")
        self.git("commit", "-qm", "symlink")
        self.assert_rejected_without_writes()

    def test_type_conflict_fails_before_any_file_is_copied(self):
        (self.target / "portal/a space.php").unlink()
        (self.target / "portal/a space.php").mkdir()
        self.assert_rejected_without_writes()

    def test_missing_basic_tool_is_reported_without_writes(self):
        (self.tools / "cmp").unlink()
        result = self.assert_rejected_without_writes()
        self.assertIn("Program cmp tidak tersedia", result.stderr)

    def test_comparison_error_is_not_treated_as_changed_content(self):
        (self.tools / "cmp").unlink()
        (self.tools / "cmp").write_text("#!/bin/bash\nexit 2\n")
        os.chmod(self.tools / "cmp", 0o755)
        result = self.assert_rejected_without_writes()
        self.assertIn("Tidak dapat membandingkan file", result.stderr)

    def test_ignored_local_files_are_never_deployed(self):
        self.write(self.source, ".gitignore", "app/local.php\n")
        self.git("add", ".")
        self.git("commit", "-qm", "ignore local file")
        self.write(self.source, "app/local.php", "ignored-host-local-file")
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse((self.target / "app/local.php").exists())


if __name__ == "__main__":
    unittest.main(verbosity=2)
