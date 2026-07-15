import argparse
import io
import os
import sys

import msoffcrypto


def decrypt_excel(input_path: str, output_path: str, password: str) -> None:
    with open(input_path, "rb") as source_file:
        office_file = msoffcrypto.OfficeFile(source_file)
        office_file.load_key(password=password)

        decrypted_data = io.BytesIO()
        office_file.decrypt(decrypted_data)

    decrypted_data.seek(0)
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    with open(output_path, "wb") as target_file:
        target_file.write(decrypted_data.read())


def main() -> int:
    parser = argparse.ArgumentParser(description="Decrypt password-protected Excel file")
    parser.add_argument("input_path")
    parser.add_argument("output_path")
    parser.add_argument("--password", default="MBTC2026")
    args = parser.parse_args()

    try:
        decrypt_excel(args.input_path, args.output_path, args.password)
        print("OK")
        return 0
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
