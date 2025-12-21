#!/usr/bin/env python3
"""Download every chunk from /wp-json/traxs/v1/workorders and merge into a single PDF."""

import argparse
import getpass
import os
import sys
import tempfile

import requests
from PyPDF2 import PdfMerger


def download_chunked_pdf(base_url, username, password, chunk_size, output_path):
    session = requests.Session()
    session.auth = (username, password)
    chunk = 0
    temp_files = []
    total_chunks = None

    while True:
        params = {'chunk': chunk, 'chunk_size': chunk_size}
        resp = session.get(f'{base_url}/wp-json/traxs/v1/workorders', params=params, stream=True)
        resp.raise_for_status()
        if total_chunks is None:
            total_chunks = int(resp.headers.get('X-Traxs-Chunk-Total', '1'))
        tmpf = tempfile.NamedTemporaryFile(delete=False, suffix=f'.chunk{chunk}.pdf')
        with tmpf as fh:
            for block in resp.iter_content(8192):
                fh.write(block)
        temp_files.append(tmpf.name)
        chunk += 1
        if chunk >= total_chunks:
            break

    merger = PdfMerger()
    for chunk_file in temp_files:
        merger.append(chunk_file)
    merger.write(output_path)
    merger.close()

    for chunk_file in temp_files:
        os.unlink(chunk_file)


def main():
    parser = argparse.ArgumentParser(description='Fetch and merge Traxs work order PDFs.')
    parser.add_argument('--url', '-u', required=True, help='Base WordPress URL (no trailing slash).')
    parser.add_argument('--user', '-U', required=True, help='WordPress username.')
    parser.add_argument('--password', '-P', help='Application password for the user.')
    parser.add_argument('--chunk-size', '-c', type=int, default=8, help='Chunk size to request.')
    parser.add_argument('--output', '-o', default='traxs-workorders.pdf', help='Merged PDF output path.')
    args = parser.parse_args()

    password = args.password or getpass.getpass('Application password: ')
    download_chunked_pdf(args.url.rstrip('/'), args.user, password, args.chunk_size, args.output)


if __name__ == '__main__':
    main()
