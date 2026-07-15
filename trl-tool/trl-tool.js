// TRL Tool: generate sample TRL Excel files
(async function(){
  // Robust loader for `trl-tool.json`: try several candidate URLs and fall back to empty array.
  async function loadBillersFromCandidates() {
    const tried = [];
    const candidates = [
      'trl-tool.json',
      './trl-tool.json'
    ];
    try {
      const pathbase = (location.pathname || '').substring(0, (location.pathname || '').lastIndexOf('/') + 1);
      if (pathbase) candidates.push(pathbase + 'trl-tool.json');
      if (window.location && window.location.origin) candidates.push(window.location.origin + pathbase + 'trl-tool.json');
    } catch (e) {}

    for (const url of candidates) {
      try {
        tried.push(url);
        const res = await fetch(url);
        if (!res.ok) {
          console.warn('trl-tool.json fetch not OK:', url, res.status);
          continue;
        }
        const j = await res.json();
        // accept array or object with `data` array
        if (Array.isArray(j)) {
          console.info('Loaded billers from', url);
          return j;
        }
        if (j && Array.isArray(j.data)) {
          console.info('Loaded billers.data from', url);
          return j.data;
        }
        console.warn('trl-tool.json did not contain expected array at', url);
      } catch (err) {
        console.warn('Failed to fetch trl-tool.json from', url, err);
      }
    }
    console.error('Unable to load trl-tool.json. Tried:', tried);
    return [];
  }

  if (location.protocol === 'file:') {
    console.warn('Page opened via file:// — fetching local JSON may be blocked. Serve via a local HTTP server (XAMPP) and open via http://localhost/...');
  }

  const billers = await loadBillersFromCandidates();
  // load branches for PAYMENT BRANCH fields
  let branches = [];
  try {
    const bres = await fetch('branch.json');
    branches = await bres.json();
    // normalize to ensure id and branch_name exist
    branches = branches.map(function(b, i){
      return {
        id: (b.branch_id !== undefined && b.branch_id !== null) ? b.branch_id : (b.id !== undefined && b.id !== null) ? b.id : (i+1),
        branch_name: b.branch_name || b.name || ('ML BRANCH ' + (i+1))
      };
    });
  } catch (e) {
    branches = [];
  }

  const dom = {
    rowsInput: document.getElementById('rowsCount'),
    generateBtn: document.getElementById('generateBtn'),
    tableBody: document.getElementById('sampleBody'),
    downloadBtn: document.getElementById('downloadBtn'),
    fileNameInput: document.getElementById('fileName'),
    billerSelect: document.getElementById('billerSelect'),
    billerList: document.getElementById('billerList') // may be null now that we use a select
  };
  dom.typeSelect = document.getElementById('typeSelect');
  // allowed Type of Request values
  const allowedTypes = [
    'NO PAYMENT RECEIVED',
    'DOUBLE POSTING',
    'MULTI POSTING',
    'WRONG BILLER',
    'OVERSTATED AMOUNT',
    'CANCELLED TRANSACTION',
    'TRIPLE POSTING',
    'UNREFLECTED TRXN'
  ];

  function randInt(min,max){return Math.floor(Math.random()*(max-min+1))+min}
  function pick(arr){return arr[randInt(0,arr.length-1)]}
  function randAmount(){return randInt(50,999999)}
  function pad(n,len){return String(n).padStart(len,'0')}
  function maybe(prob){return Math.random() < prob}

  const firstNames = ['JUAN','MARIA','PEDRO','ANA','JOSE','LUISA','MARK','ALTHEA','JOHN','PAULO','ANGELA','ROMEO'];
  const lastNames = ['DELA CRUZ','SANTOS','REYES','GARCIA','MENDOZA','RAMOS','AQUINO','CASTILLO','NAVARRO','FLORES'];
  const branchPrefixes = ['ML BRANCH','ML EXPRESS','ML WALLET HUB','ML PAY CENTER'];

  let generationSeq = 0;
  let generatedRefs = new Set();
  let generatedAccounts = new Set();

  function randomRef(){
    // collision-safe ref generation for each Generate click
    var attempts = 0;
    while (attempts < 1000) {
      attempts++;
      const base = Date.now().toString().slice(-9);
      const seq = pad(generationSeq++, 4);
      const noise = pad(randInt(0, 99999), 5);
      const ref = 'BPP' + base + seq + noise;
      if (!generatedRefs.has(ref)) {
        generatedRefs.add(ref);
        return ref;
      }
    }

    // hard fallback (practically unreachable)
    const forced = 'BPP' + Date.now() + pad(generationSeq++, 6);
    generatedRefs.add(forced);
    return forced;
  }

  function randomAccountNo(){
    // 13-digit unique-ish account number for each batch
    var attempts = 0;
    while (attempts < 1000) {
      attempts++;
      const acct = pad(randInt(100000000000, 9999999999999), 13);
      if (!generatedAccounts.has(acct)) {
        generatedAccounts.add(acct);
        return acct;
      }
    }
    const forced = pad(Date.now() % 10000000000000, 13);
    generatedAccounts.add(forced);
    return forced;
  }

  function randomCustomerName(){
    return pick(firstNames) + ' ' + pick(lastNames);
  }

  function formatDate(d){
    // example format: 2023-03-15 03:04:44 PM
    const yyyy = d.getFullYear();
    const mm = pad(d.getMonth()+1,2);
    const dd = pad(d.getDate(),2);
    let hh = d.getHours();
    const ampm = hh>=12? 'PM':'AM';
    hh = hh%12; if (hh===0) hh=12;
    const hhs = pad(hh,2);
    const mins = pad(d.getMinutes(),2);
    const secs = pad(d.getSeconds(),2);
    return `${yyyy}-${mm}-${dd} ${hhs}:${mins}:${secs} ${ampm}`;
  }

  // Generate a random Date object with year between minYear and maxYear (inclusive)
  function randomDateBetweenYears(minYear, maxYear) {
    var y = randInt(minYear, maxYear);
    var monthIdx = randInt(0, 11); // 0-based month index
    var daysInMonth = new Date(y, monthIdx + 1, 0).getDate();
    var day = randInt(1, daysInMonth);
    var hour = randInt(0, 23);
    var minute = randInt(0, 59);
    var second = randInt(0, 59);
    return new Date(y, monthIdx, day, hour, minute, second);
  }

  // currently selected wrong biller; null => use random per-row (All)
  var selectedWrongBiller = null;
  // selected request type from dropdown; empty string = default (Select request type)
  var selectedRequestType = '';
  function makeRow(){
    var wrong;
    if (selectedWrongBiller && selectedWrongBiller.id) {
      wrong = selectedWrongBiller;
    } else {
      wrong = pick(billers);
    }
    // pick a different correct biller sometimes
    let correct = pick(billers);
    if (correct.id === wrong.id) {
      correct = billers[(billers.indexOf(wrong)+1) % billers.length];
    }

    var branchId, branchName;
    if (branches && branches.length) {
      var b = pick(branches);
      branchId = b.id;
      branchName = b.branch_name;
    } else {
      branchId = randInt(1, 999);
      branchName = pick(branchPrefixes) + ' ' + randInt(1, 400);
    }

    // choose type and build reason accordingly
    const showAll = (selectedRequestType && selectedRequestType.toUpperCase() === 'ALL');
    const type = (selectedRequestType && selectedRequestType.trim() !== '' && !showAll) ? selectedRequestType : pick(allowedTypes);

    // fields for wrong/correct/difference when relevant
    var reportedVal = null, actualVal = null, diffVal = null;

    // helper to format currency like 11,092.00
    function fmtAmt(v){
      return Number(v).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    // default amounts
    let amount = randAmount();
    let reason = '';

    switch(type) {
      case 'NO PAYMENT RECEIVED':
        reason = pick([
          'NO PAYMENT RECEIVED FROM THE CUSTOMER',
          'CUSTOMER DID NOT COMPLETE PAYMENT',
          'TRANSACTION ATTEMPTED BUT NO PAYMENT RECEIVED'
        ]);
        break;
      case 'DOUBLE POSTING':
      case 'MULTI POSTING':
        reason = pick([
          'DOUBLE POSTING',
          'TRANSACTION POSTED TWICE',
          'DUPLICATE POSTING OF SAME PAYMENT'
        ]);
        break;
      case 'WRONG BILLER':
        // state intended correct biller in reason
        reason = pick([
          'WRONG BILLER - INTENDED FOR ' + correct.name,
          'WRONG BILLER. SHOULD BE POSTED TO ' + correct.name,
          'MISROUTED BILLER; INTENDED BILLER IS ' + correct.name
        ]);
        break;
      case 'OVERSTATED AMOUNT':
        // make amount overstated vs correct
        const correctAmt = Math.max(50, amount - randInt(1000, 50000));
        const diff = amount - correctAmt;
        reportedVal = amount;
        actualVal = correctAmt;
        diffVal = diff;
        reason = pick([
          'OVERSTATED AMOUNT PHP ' + fmtAmt(amount) + ' INSTEAD OF PHP ' + fmtAmt(correctAmt) + ' WITH THE DIFFERENCE OF PHP ' + fmtAmt(diff),
          'OVERPOSTED AMOUNT: PHP ' + fmtAmt(amount) + ' SHOULD BE PHP ' + fmtAmt(correctAmt) + ' (DIFF PHP ' + fmtAmt(diff) + ')'
        ]);
        break;
      case 'UNREFLECTED TRXN':
        reason = pick([
          'UNREFLECTED TXN TO ML REPORT',
          'TRANSACTION NOT REFLECTED IN ML REPORT',
          'POSTED PAYMENT IS UNREFLECTED IN REPORTING'
        ]);
        break;
      case 'TRIPLE POSTING':
        reason = pick([
          'TRIPLE POSTING',
          'TRANSACTION POSTED THREE TIMES',
          'TRIPLE ENTRY OF SAME PAYMENT'
        ]);
        break;
      case 'CANCELLED TRANSACTION':
        // wrong posted amount smaller or larger with variant reasons
        const posted = Math.max(50, randInt(100, 99999));
        const adjustment = randInt(100, 20000);
        const correctedHigher = maybe(0.5);
        const correctAmt2 = correctedHigher ? (posted + adjustment) : Math.max(50, posted - adjustment);
        amount = posted;
        reportedVal = posted;
        actualVal = correctAmt2;
        reason = pick([
          'CANCELLED TRANSACTION - Wrong amount posted (' + fmtAmt(posted) + ') instead of (' + fmtAmt(correctAmt2) + ')',
          'CANCELLED TRANSACTION WRONG AMOUNT ENCODED (' + fmtAmt(posted) + ') instead of (' + fmtAmt(correctAmt2) + ')'
        ]);
        break;
      default:
        reason = 'INTENDED FOR ' + correct.name;
    }

    // Build row with conditional columns based on selectedRequestType
    const row = {
      // random transfer datetime between 2015 and 2026 (inclusive)
      'TRANS. DATE/TIME': formatDate(randomDateBetweenYears(2015, 2026)),
      'REF. NO.': randomRef(),
      // Use numeric prefix for ID when possible (e.g., "1028-01" -> "1028")
      'WRONG BILLER ID': (function(id){
        try {
          var s = String(id || '');
          var m = s.match(/^(\d+)/);
          return m ? m[1] : s;
        } catch(e) { return String(id || ''); }
      })(wrong.id),
      'BILLER NAME': wrong.name,
      'ACCOUNT NO.': randomAccountNo(),
      'NAME': randomCustomerName(),
      'PAYMENT BRANCH ID': branchId,
      'PAYMENT BRANCH': branchName,
      'AMOUNT': amount,
      'TYPE OF REQUEST': type
    };

    // Show type-specific supplemental columns
    if (type === 'WRONG BILLER') {
      row['CORRECT BILLER ID'] = (function(id){
        try {
          var s = String(id || '');
          var m = s.match(/^(\d+)/);
          return m ? m[1] : s;
        } catch(e) { return String(id || ''); }
      })(correct.id);
      row['CORRECT BILLER NAME'] = correct.name;
    }
    if (type === 'OVERSTATED AMOUNT') {
      row['WRONG AMOUNT'] = (reportedVal !== null && reportedVal !== undefined) ? fmtAmt(reportedVal) : '';
      row['CORRECT AMOUNT'] = (actualVal !== null && actualVal !== undefined) ? fmtAmt(actualVal) : '';
      row['DIFFERENCE'] = (diffVal !== null && diffVal !== undefined) ? fmtAmt(diffVal) : '';
    }
    if (type === 'CANCELLED TRANSACTION') {
      row['WRONG AMOUNT'] = (reportedVal !== null && reportedVal !== undefined) ? fmtAmt(reportedVal) : '';
      row['CORRECT AMOUNT'] = (actualVal !== null && actualVal !== undefined) ? fmtAmt(actualVal) : '';
    }

    // If the user selected "All", always include supplemental columns (empty when not applicable)
    if (showAll) {
      // correct biller fields belong only to WRONG BILLER rows
      row['CORRECT BILLER ID'] = (type === 'WRONG BILLER') ? (function(id){ try { var s=String(id||''); var m=s.match(/^(\d+)/); return m?m[1]:s; } catch(e){return String(id||'');} })(correct.id) : '';
      row['CORRECT BILLER NAME'] = (type === 'WRONG BILLER') ? (correct.name || '') : '';
      // ensure amount-related supplemental columns exist (empty string when not applicable)
      row['WRONG AMOUNT'] = (reportedVal !== null && reportedVal !== undefined) ? fmtAmt(reportedVal) : (row['WRONG AMOUNT'] !== undefined ? row['WRONG AMOUNT'] : '');
      row['CORRECT AMOUNT'] = (actualVal !== null && actualVal !== undefined) ? fmtAmt(actualVal) : (row['CORRECT AMOUNT'] !== undefined ? row['CORRECT AMOUNT'] : '');
      row['DIFFERENCE'] = (diffVal !== null && diffVal !== undefined) ? fmtAmt(diffVal) : (row['DIFFERENCE'] !== undefined ? row['DIFFERENCE'] : '');
    }

    row['REASON'] = reason;

    return row;
  }

  function renderTable(rows){
    // Render header dynamically based on first row keys
    const table = document.getElementById('sampleTable');
    const thead = table.querySelector('thead');
    if (rows.length === 0) {
      if (thead) thead.innerHTML = '';
      dom.tableBody.innerHTML = '';
      return;
    }
    const keys = Object.keys(rows[0]);
    if (thead) {
      thead.innerHTML = '';
      const trh = document.createElement('tr');
      keys.forEach(k=>{
        const th = document.createElement('th');
        th.textContent = k;
        trh.appendChild(th);
      });
      thead.appendChild(trh);
    }

    dom.tableBody.innerHTML = '';
    rows.forEach(r=>{
      const tr = document.createElement('tr');
      keys.forEach(k=>{
        const td = document.createElement('td');
        td.textContent = r[k] !== undefined && r[k] !== null ? r[k] : '';
        tr.appendChild(td);
      });
      dom.tableBody.appendChild(tr);
    });
  }

  function exportXLSX(rows, filename){
    const ws_data = [];
    if (rows.length === 0) return;

    // Use a stable header order so exported values never shift columns.
    const preferredHeaders = [
      'TRANS. DATE/TIME',
      'REF. NO.',
      'WRONG BILLER ID',
      'BILLER NAME',
      'ACCOUNT NO.',
      'NAME',
      'PAYMENT BRANCH ID',
      'PAYMENT BRANCH',
      'AMOUNT',
      'TYPE OF REQUEST',
      'CORRECT BILLER ID',
      'CORRECT BILLER NAME',
      'WRONG AMOUNT',
      'CORRECT AMOUNT',
      'DIFFERENCE',
      'REASON'
    ];

    const headerSet = {};
    rows.forEach(function(r){
      Object.keys(r).forEach(function(k){
        headerSet[k] = true;
      });
    });

    const headers = preferredHeaders.filter(function(h){ return !!headerSet[h]; });
    ws_data.push(headers);
    rows.forEach(function(r){
      ws_data.push(headers.map(function(h){
        const v = r[h];
        return (v === undefined || v === null) ? '' : v;
      }));
    });

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(ws_data);
    XLSX.utils.book_append_sheet(wb, ws, 'TRL');
    const wbout = XLSX.write(wb, {bookType:'xlsx', type:'array'});
    const blob = new Blob([wbout], {type:'application/octet-stream'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename || ('trl-sample.xlsx');
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  let currentRows = [];

  dom.generateBtn.addEventListener('click', function(){
    const n = Math.max(1, Math.min(10000, parseInt(dom.rowsInput.value) || 100));
    // reset uniqueness maps every generation batch
    generatedRefs = new Set();
    generatedAccounts = new Set();
    generationSeq = 0;
    const rows = [];
    for (let i=0;i<n;i++){ rows.push(makeRow()); }
    currentRows = rows;
    renderTable(rows.slice(0,500));
    const genEl = document.getElementById('generatedCount');
    if (genEl) genEl.textContent = n;
    dom.downloadBtn.disabled = false;
  });

  // When the type selector changes, regenerate current rows with the same count to reflect column changes
  if (dom.typeSelect) {
    dom.typeSelect.addEventListener('change', function(e){
      selectedRequestType = (dom.typeSelect.value || '').trim();
      // if there are existing generated rows, regenerate a new batch preserving the count
      const count = currentRows.length || Math.max(1, parseInt(dom.rowsInput.value) || 100);
      generatedRefs = new Set(); generatedAccounts = new Set(); generationSeq = 0;
      const newRows = [];
      for (let i=0;i<count;i++) { newRows.push(makeRow()); }
      currentRows = newRows;
      renderTable(newRows.slice(0,500));
      const genEl2 = document.getElementById('generatedCount');
      if (genEl2) genEl2.textContent = count;
      dom.downloadBtn.disabled = false;
    });
  }

  // populate biller datalist and wire selection behavior
  function sanitizeFileName(name) {
    return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
  }

  // Fill biller select (include an 'All' pseudo-option as placeholder)
  (function populateBillers(){
    const targetList = dom.billerList || dom.billerSelect;
    if (!targetList) return;

    // ensure 'All' exists for select as first option
    if (dom.billerSelect && dom.billerSelect.tagName === 'SELECT') {
      // keep existing All option already present in HTML
    } else if (dom.billerList) {
      const optAll = document.createElement('option');
      optAll.value = 'All';
      dom.billerList.appendChild(optAll);
    }

    billers.forEach(function(b){
      var o = document.createElement('option');
      o.value = b.name || '';
      // for <select>, also show text
      if (targetList.tagName === 'SELECT') o.textContent = b.name || '';
      targetList.appendChild(o);
    });
    // default placeholder
    dom.billerSelect.value = 'All';
    selectedWrongBiller = null;

    function handleBillerChange(e){
      var v = (dom.billerSelect.value || '').trim();
      if (!v || v.toLowerCase() === 'all') {
        selectedWrongBiller = null;
        dom.fileNameInput.value = 'trl-sample-all.xlsx';
        return;
      }

      // 1) exact name match (case-insensitive)
      var foundByExactName = billers.find(function(b){ return b.name && b.name.toLowerCase() === v.toLowerCase(); });
      if (foundByExactName) {
        selectedWrongBiller = foundByExactName;
        dom.fileNameInput.value = 'trl-sample-' + sanitizeFileName(foundByExactName.name) + '.xlsx';
        return;
      }

      // 2) try id string match like "1028-01"
      var foundById = billers.find(function(b){ return String(b.id) === v; });
      if (foundById) {
        selectedWrongBiller = foundById;
        dom.fileNameInput.value = 'trl-sample-' + sanitizeFileName(foundById.name) + '.xlsx';
        return;
      }

      // 3) try to parse "id - name" in case someone typed it manually
      var m = v.match(/^\s*(.*?)\s*-\s*(.+)$/);
      if (m) {
        var idStr = (m[1] || '').trim();
        var found = billers.find(function(b){ return String(b.id) === idStr; });
        if (found) {
          selectedWrongBiller = found;
          dom.fileNameInput.value = 'trl-sample-' + sanitizeFileName(found.name) + '.xlsx';
          return;
        }
      }

      // 4) try contains name (case-insensitive)
      var foundByName = billers.find(function(b){ return b.name && b.name.toLowerCase().indexOf(v.toLowerCase()) !== -1; });
      if (foundByName) {
        selectedWrongBiller = foundByName;
        dom.fileNameInput.value = 'trl-sample-' + sanitizeFileName(foundByName.name) + '.xlsx';
        return;
      }

      // fallback to All
      selectedWrongBiller = null;
      dom.fileNameInput.value = 'trl-sample-all.xlsx';
    }

    // attach handler to both input and change to cover both input/select use
    dom.billerSelect.addEventListener('change', handleBillerChange);
    dom.billerSelect.addEventListener('input', handleBillerChange);
  })();

  dom.downloadBtn.addEventListener('click', function(){
    const fname = dom.fileNameInput.value.trim() || ('trl-sample-' + new Date().toISOString().slice(0,10) + '.xlsx');
    exportXLSX(currentRows, fname);
  });

})();
