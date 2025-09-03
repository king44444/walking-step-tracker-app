#!/usr/bin/env python3
"""
gather_code.py  –  Consolidate source files across a project.

Usage:
  gather_code.py [--root ROOT] [--ext EXTENSIONS] [--skip DIRS] [--skip-files NAMES] [--output FILE]
                 [--version]

Examples:
  # Consolidate all .py and .md files, skipping venv and .git:
  gather_code.py --ext .py,.md --skip .git,venv --output all_code.txt

  # Just print version and exit:
  gather_code.py --version
"""

import os
import sys
import argparse
import logging

__version__ = "1.1.0"

# default extensions and directories to skip
DEFAULT_EXTS = [".py", ".js", ".jsx", ".ts", ".tsx", ".mjs", ".cjs", ".json", ".yml", ".yaml", ".html", ".htm"]
DEFAULT_SKIP_DIRS = {".git", "__pycache__", "venv", ".venv", "env", ".env", "node_modules", "dist", "build", ".idea", ".vscode", "tmp", "temp", "cache", "logs", "log", ".cache", ".logs", ".next", ".nuxt", ".svelte-kit", ".turbo", ".vite", ".parcel-cache", ".rollup.cache", ".pnpm-store", ".yarn", ".yalc", "coverage", ".nyc_output", "storybook-static", ".vercel", "out"}
DEFAULT_SKIP_FILES = {"package-lock.json", "pnpm-lock.yaml", "yarn.lock", "bun.lockb"}

def parse_args():
    p = argparse.ArgumentParser(
        description="Consolidate code files into a single text file."
    )
    p.add_argument(
        "--root",
        "-r",
        default=".",
        help="Root directory to start searching (default: current dir)."
    )
    p.add_argument(
        "--ext",
        "-e",
        default=",".join(DEFAULT_EXTS),
        help="Comma-separated list of file extensions to include (e.g. .py,.js)."
    )
    p.add_argument(
        "--skip",
        "-s",
        default=",".join(DEFAULT_SKIP_DIRS),
        help="Comma-separated list of directory names to skip."
    )
    p.add_argument(
        "--skip-files",
        "-F",
        default=",".join(DEFAULT_SKIP_FILES),
        help="Comma-separated list of file names to skip (e.g. lockfiles)."
    )
    p.add_argument(
        "--output",
        "-o",
        default="consolidated_code.txt",
        help="Path of the output file (default: consolidated_code.txt)."
    )
    p.add_argument(
        "--version",
        action="store_true",
        help="Show script version and exit."
    )
    return p.parse_args()

def should_skip_dir(dirname, skip_set):
    """Return True if `dirname` should be skipped."""
    return dirname in skip_set

def gather_files(root, exts, skip_dirs, skip_files):
    """Yield full paths of files under root with allowed extensions."""
    for dirpath, dirs, files in os.walk(root):
        # modify dirs in-place to skip unwanted subdirs
        dirs[:] = [d for d in dirs if not should_skip_dir(d, skip_dirs)]
        for fname in files:
            if fname in skip_files:
                continue
            if os.path.splitext(fname)[1] in exts:
                yield os.path.join(dirpath, fname)

def consolidate(files, out_path):
    """Read each file, append to out_path, logging errors per file."""
    total = 0
    errors = 0
    with open(out_path, "w", encoding="utf-8") as out_f:
        out_f.write(f"# Consolidated on {os.path.abspath(out_path)}\n")
        out_f.write(f"# Script version: {__version__}\n\n")
        for filepath in files:
            total += 1
            try:
                with open(filepath, "r", encoding="utf-8", errors="replace") as f:
                    content = f.read()
                out_f.write(f"\n\n# ===== FILE: {filepath} =====\n\n")
                out_f.write(content)
            except Exception as e:
                errors += 1
                logging.warning(f"Error reading {filepath}: {e}")
    return total, errors

def main():
    args = parse_args()
    if args.version:
        print(f"gather_code.py version {__version__}")
        sys.exit(0)

    # configure logging
    logging.basicConfig(
        level=logging.INFO,
        format="%(levelname)-8s %(message)s"
    )

    exts = {e.strip() for e in args.ext.split(",") if e.strip()}
    skip_dirs = {d.strip() for d in args.skip.split(",") if d.strip()}
    skip_files = {f.strip() for f in args.skip_files.split(",") if f.strip()}

    logging.info(f"Starting walk at root: {args.root}")
    logging.info(f"Including extensions: {sorted(exts)}")
    logging.info(f"Skipping directories: {sorted(skip_dirs)}")
    logging.info(f"Skipping files: {sorted(skip_files)}")
    logging.info(f"Output will be saved to: {args.output}")

    files = list(gather_files(args.root, exts, skip_dirs, skip_files))
    logging.info(f"Found {len(files)} files to process.")

    total, errors = consolidate(files, args.output)
    logging.info(f"Consolidated {total} files with {errors} errors.")
    if errors:
        logging.warning("Some files could not be read; check warnings above.")

if __name__ == "__main__":
    main()
