Install `msoffcrypto-tool` (quick guide)

1) Use the same Python that runs your script

```powershell
python -c "import sys; print(sys.executable)"
```

2) Install the package into that interpreter

```powershell
python -m pip install msoffcrypto-tool
```

Alternative package name (both provide the module):

```powershell
python -m pip install msoffcrypto
```

3) If you prefer a virtualenv inside the project

```powershell
python -m venv .venv
.venv\Scripts\Activate.ps1   # PowerShell
python -m pip install msoffcrypto-tool
```

4) Run your decrypt script with the same `python`

```powershell
python decrypt_excel.py
```

Troubleshooting
- If you still see "No module named 'msoffcrypto'", confirm `python` above matches the interpreter used to install the package.
- To inspect whether the package is visible:

```powershell
python -c "import pkgutil; print(pkgutil.find_loader('msoffcrypto'))"
```

PowerShell note (common pitfall)

- In PowerShell the `&` character is not a command separator and will raise a parser error if used like a shell `&` in Unix/Cmd. Use `;` to run sequential commands or run them separately.

Examples for PowerShell:

```powershell
python -m pip install msoffcrypto-tool; python c:\xampp\htdocs\BillsPayment\exceldecryptpassword\decrypt_excel.py

# or run separately
python -m pip install msoffcrypto-tool
python c:\xampp\htdocs\BillsPayment\exceldecryptpassword\decrypt_excel.py
```

That's it — paste any error output if problems continue.

