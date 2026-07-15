(() => {
  const dropArea = document.getElementById('dropZone') || document.getElementById('drop-area');
  const fileInput = document.getElementById('fileInput') || document.getElementById('file-input');
  const chooseBtn = document.getElementById('choose-file');
  const clearBtn = document.getElementById('clear-btn');
  const fetchBtn = document.getElementById('fetch');
  const output = document.getElementById('output');
  let selectedFile = null;

  if (chooseBtn) chooseBtn.addEventListener('click', () => fileInput.click());
  if (clearBtn) clearBtn.addEventListener('click', clearAll);

  // allow clicking the whole drop area to open file picker
  if (dropArea) {
    dropArea.addEventListener('click', (e) => {
      // ignore clicks that are on the input itself
      if (e.target && e.target.tagName === 'INPUT') return;
      if (fileInput) fileInput.click();
    });
  }

  // hide result panel initially
  const resultPanel = document.querySelector('.result-panel');
  if (resultPanel) resultPanel.classList.add('hidden');

  function clearAll(){
    selectedFile = null;
    if (fileInput) fileInput.value = '';
    if (dropArea) {
      // restore default text
      const p = dropArea.querySelector('.drop-title') || dropArea.querySelector('p');
      if (p) p.textContent = 'Drag & drop Excel files';
      dropArea.classList.remove('dragover');
    }
    if (output) output.innerHTML = '';
    if (resultPanel) resultPanel.classList.add('hidden');
  }

  fileInput.addEventListener('change', (e) => {
    if (e.target.files && e.target.files[0]) {
      selectedFile = e.target.files[0];
      dropArea.classList.remove('dragover');
      dropArea.querySelector('p').textContent = `Selected: ${selectedFile.name}`;
    }
  });

  ['dragenter', 'dragover'].forEach(evt => {
    dropArea.addEventListener(evt, (e) => {
      e.preventDefault();
      e.stopPropagation();
      dropArea.classList.add('dragover');
    });
  });

  ['dragleave', 'drop'].forEach(evt => {
    dropArea.addEventListener(evt, (e) => {
      e.preventDefault();
      e.stopPropagation();
      dropArea.classList.remove('dragover');
    });
  });

  dropArea.addEventListener('drop', (e) => {
    const dt = e.dataTransfer;
    if (dt && dt.files && dt.files[0]) {
      selectedFile = dt.files[0];
      dropArea.querySelector('p').textContent = `Selected: ${selectedFile.name}`;
    }
  });

  function numToCol(n) {
    let s = '';
    while (n > 0) {
      const m = (n - 1) % 26;
      s = String.fromCharCode(65 + m) + s;
      n = Math.floor((n - 1) / 26);
    }
    return s;
  }

  // Send file+password to local server endpoint which runs msoffcrypto-tool
  async function postDecryptToServer(file, password) {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('password', password);

    // Adjust this URL if you run the decrypt server on a different port/host
    const resp = await fetch('http://localhost:3000/decrypt', { method: 'POST', body: fd });
    if (!resp.ok) {
      const text = await resp.text();
      throw new Error('Server returned error: ' + text);
    }
    const j = await resp.json();
    if (!j.success) throw new Error(j.error || 'Unknown server error');

    // decode base64 to ArrayBuffer
    const b64 = j.data;
    const binary = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
    return binary.buffer;
  }

  fetchBtn.addEventListener('click', () => {
    if (!selectedFile) {
      alert('Please drop or select an Excel file first.');
      return;
    }

    if (typeof XLSX === 'undefined') {
      showOutputError('XLSX library not available. The CDN may be blocked by tracking prevention. Add a local copy of xlsx.full.min.js or allow the CDN.');
      return;
    }

    showOverlay();
    const pwd = (document.getElementById('password-input') || {}).value || undefined;
    const reader = new FileReader();
    reader.onload = (ev) => {
      const data = ev.target.result;
      try {
        const opts = {type: 'array'};
        if (pwd) opts.password = pwd;
        const workbook = XLSX.read(data, opts);
        renderWorkbook(workbook);
        // show results panel when data is rendered
        if (resultPanel) resultPanel.classList.remove('hidden');
      } catch (err) {
        console.error(err);
        const msg = String(err || '').toLowerCase();

        // inspect header to provide more precise guidance
        function detectFileType(ab){
          try{
            const bytes = new Uint8Array(ab);
            if(bytes.length >= 4){
              // PK.. -> XLSX (zip)
              if(bytes[0] === 0x50 && bytes[1] === 0x4B && bytes[2] === 0x03 && bytes[3] === 0x04) return 'xlsx-zip';
              // Compound File Binary (old .xls/.doc) -> D0 CF 11 E0
              if(bytes[0] === 0xD0 && bytes[1] === 0xCF && bytes[2] === 0x11 && bytes[3] === 0xE0) return 'cfb';
            }
          }catch(e){/* ignore */}
          return 'unknown';
        }

        const ftype = detectFileType(data);

        if(msg.includes('encryption') || msg.includes('password') || msg.includes('file is encrypted') || msg.includes('encryption flags') || msg.includes('algid')){
          // Known encryption-related failure
          if(ftype === 'xlsx-zip'){
            // XLSX encrypted with unsupported algorithm
            showOutputError('Failed to decrypt XLSX: the file uses an encryption algorithm not supported by this browser library (Encryption Flags/AlgID mismatch).\n\nOptions:\n- Open the file in Excel and re-save without a password or with the standard Office encryption.\n- Remove protection or export a copy without encryption.\n- Alternatively, attempt server-side decryption if your local server has msoffcrypto-tool installed.');

            // Offer server-side decryption via local CLI tool
            if (confirm('Attempt server-side decryption using local msoffcrypto-tool? (requires the tool installed on the server)')) {
              const serverPw = prompt('Password for file (cancel to abort)');
              if (serverPw !== null) {
                showOverlay();
                (async () => {
                  try {
                    const decArr = await postDecryptToServer(selectedFile, serverPw);
                    const wb = XLSX.read(decArr, { type: 'array' });
                    renderWorkbook(wb);
                    return;
                  } catch (e2) {
                    console.error('Server decrypt failed', e2);
                    showOutputError('Server-side decrypt failed: ' + (e2.message || e2));
                  } finally {
                    hideOverlay();
                  }
                })();
              }
            }
          } else if(ftype === 'cfb'){
            showOutputError('Failed to decrypt legacy Excel (.xls) file. The browser library may not support the file\'s encryption. Try opening the file in Excel and saving as a modern .xlsx without a password, or remove protection before testing.');
          } else {
            showOutputError('Failed to parse file with provided password. Please check the password and try again.');
          }
        } else {
          showOutputError('Failed to parse file: ' + (err.message || err));
        }
      } finally {
        hideOverlay();
      }
    };
    reader.onerror = (err) => {
      console.error(err);
      hideOverlay();
      showOutputError('Failed to read file.');
    };
    reader.readAsArrayBuffer(selectedFile);
  });

  function renderWorkbook(workbook) {
    output.innerHTML = '';
    // clear any previous error styling
    output.classList.remove('error');
    workbook.SheetNames.forEach(sheetName => {
      const sheet = workbook.Sheets[sheetName];
      const rows = XLSX.utils.sheet_to_json(sheet, {header:1});

      const title = document.createElement('h2');
      title.textContent = `Sheet: ${sheetName}`;
      output.appendChild(title);

      const table = document.createElement('table');
      rows.forEach((rowArr, rIdx) => {
        const tr = document.createElement('tr');
        rowArr.forEach((cellVal, cIdx) => {
          const td = document.createElement('td');
          const addr = `${numToCol(cIdx+1)}${rIdx+1}`;
          td.dataset.addr = addr;
          // native tooltip on hover showing cell address (e.g. A1, B2)
          td.title = addr;
          td.className = 'cell';
          td.textContent = cellVal === undefined ? '' : cellVal;
          tr.appendChild(td);
        });
        table.appendChild(tr);
      });
      output.appendChild(table);

      // cell address list
      const list = document.createElement('div');
      list.className = 'cell-list';
      const items = [];
      for (let r = 0; r < rows.length; r++) {
        const rowArr = rows[r] || [];
        for (let c = 0; c < rowArr.length; c++) {
          const val = rowArr[c];
          if (val !== undefined && val !== null && String(val).trim() !== '') {
            const addr = `${numToCol(c+1)}${r+1}`;
            items.push(`${addr} - ${val}`);
          }
        }
      }
      if (items.length === 0) {
        list.textContent = '(no data found)';
      } else {
        const pre = document.createElement('pre');
        pre.textContent = items.join('\n');
        list.appendChild(pre);
      }
      output.appendChild(list);
    });
    // ensure result area is allowed to expand (no inner cap)
    const tableWrap = document.querySelector('.table-wrap') || document.getElementById('output');
    if (tableWrap) { tableWrap.style.maxHeight = ''; tableWrap.style.overflow = 'visible'; }
  }

  // removed height-capping behavior so the panel expands with content

  function showOutputError(msg) {
    output.innerHTML = '';
    output.classList.add('error');
    const errBox = document.createElement('div');
    errBox.style.color = 'crimson';
    errBox.style.whiteSpace = 'pre-wrap';
    errBox.textContent = msg;
    output.appendChild(errBox);
    const tableWrap2 = document.querySelector('.table-wrap') || document.getElementById('output');
    if (tableWrap2) { tableWrap2.style.maxHeight = ''; tableWrap2.style.overflow = 'visible'; }
  }

  const overlay = document.getElementById('loading-overlay');
  function showOverlay() { if (overlay) overlay.classList.remove('hidden'); }
  function hideOverlay() { if (overlay) overlay.classList.add('hidden'); }

})();
