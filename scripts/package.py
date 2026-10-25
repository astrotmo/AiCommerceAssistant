#!/usr/bin/env python3
"""Build an installable plugin ZIP from current sources in an isolated directory."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET
import zipfile


ROOT = Path(__file__).resolve().parents[1]
SKIP = {"node_modules", "dist", "public", ".git", "__pycache__", ".vite"}


def source_files():
    # Deliberate allowlist: never copy old bundles, local secrets or dev fixtures.
    for name in ("composer.json", "README.md", "CHANGELOG.md", "LICENSE"):
        path = ROOT / name
        if path.is_file() and not path.is_symlink():
            yield path
    yield ROOT / "docs/release.md"
    for path in sorted((ROOT / "src").rglob("*")):
        parts = path.relative_to(ROOT).parts
        if (path.is_file() and not any((ROOT.joinpath(*parts[:i])).is_symlink()
                                      for i in range(1, len(parts) + 1))
                and not SKIP.intersection(parts)
                and not any(p.startswith(".env") for p in parts)):
            yield path


def main():
    metadata = json.loads((ROOT / "composer.json").read_text())
    version = metadata["version"]
    plugin = metadata["extra"]["shopware-plugin-class"].split("\\")[-1]
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise SystemExit("Expected a stable three-part plugin version")
    if os.environ.get("GITHUB_REF_TYPE") == "tag":
        if os.environ.get("GITHUB_REF_NAME") != "v" + version:
            raise SystemExit("Tag does not match composer.json")
    output = Path(os.environ.get("RELEASE_OUTPUT", ROOT / "dist")).resolve()
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f"{plugin}-{version}.zip"
    # A failed build must never leave a previous successful ZIP in its place.
    archive.unlink(missing_ok=True)
    archive.with_suffix(".zip.sha256").unlink(missing_ok=True)
    cli = os.environ.get("SHOPWARE_CLI", "shopware-cli")
    with tempfile.TemporaryDirectory(prefix="shopware-package-") as temporary:
        staging = Path(temporary) / plugin
        staging.mkdir()
        hashes = {}
        for path in source_files():
            relative = path.relative_to(ROOT)
            target = staging / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)
            hashes[str(relative)] = hashlib.sha256(path.read_bytes()).hexdigest()
            if path.suffix == ".json":
                json.loads(path.read_text())
            if path.suffix == ".xml":
                ET.parse(path)
        shutil.copytree(ROOT / ".config", staging / ".config")
        env = dict(os.environ, DO_NOT_TRACK="1", CI="1")
        subprocess.run([cli, "extension", "build", str(staging)], cwd=temporary,
                       env=env, check=True)
        storefront = staging / "src/Resources/app/storefront/dist/storefront/js"
        if not list(storefront.rglob("*.js")):
            raise SystemExit("Missing compiled storefront bundle")
        if (staging / "src/Resources/app/administration/src/main.js").exists():
            administration = staging / "src/Resources/public/administration"
            if not list(administration.rglob("*.js")):
                raise SystemExit("Missing compiled administration bundle")
            # Vite's hidden entrypoints/manifest are runtime files; keep them.
            if not (administration / ".vite/entrypoints.json").exists():
                raise SystemExit("Missing administration entrypoints")
        info = {"plugin": plugin, "version": version,
                "shopware_build_version": "6.7.3.1", "shopware_cli_version": "0.19.5",
                "build_config_sha256": hashlib.sha256(
                    (ROOT / ".config/shopware-extension.yml").read_bytes()).hexdigest(),
                "source_sha256": hashes}
        (staging / "BUILD-INFO.json").write_text(json.dumps(info, indent=2) + "\n")
        with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as bundle:
            for path in sorted(staging.rglob("*")):
                relative = path.relative_to(staging)
                if not path.is_file() or path.is_symlink():
                    continue
                if relative.parts[0] not in {"src", "docs", "composer.json", "README.md",
                                              "CHANGELOG.md", "LICENSE", "BUILD-INFO.json"}:
                    continue
                if ("node_modules" in relative.parts or path.suffix == ".map"
                        or any(p.startswith(".env") for p in relative.parts)
                        or path.name in {"package.json", "package-lock.json", ".gitkeep"}):
                    continue
                item = zipfile.ZipInfo(f"{plugin}/{relative}", (2026, 1, 1, 0, 0, 0))
                item.external_attr = 0o100644 << 16
                bundle.writestr(item, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED)
        with zipfile.ZipFile(archive) as bundle:
            assert bundle.testzip() is None
            assert json.loads(bundle.read(f"{plugin}/composer.json"))["version"] == version
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        archive.with_suffix(".zip.sha256").write_text(f"{digest}  {archive.name}\n")
        print(f"Built and checked: {archive}")


if __name__ == "__main__":
    main()
