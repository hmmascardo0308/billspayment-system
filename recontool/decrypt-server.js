const express = require('express');
const multer = require('multer');
const cors = require('cors');
const { exec } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const app = express();
app.use(cors());

const upload = multer({ dest: os.tmpdir() });

function tryCommands(tmpIn, tmpOut, password) {
  const escIn = JSON.stringify(tmpIn);
  const escOut = JSON.stringify(tmpOut);
  const escPw = JSON.stringify(password);

  const cmds = [
    `msoffcrypto-tool -p ${password} ${tmpIn} ${tmpOut}`,
    `python -m msoffcrypto.cli -p ${password} ${tmpIn} ${tmpOut}`,
    `py -m msoffcrypto.cli -p ${password} ${tmpIn} ${tmpOut}`
  ];

  return new Promise((resolve, reject) => {
    let lastErr = '';
    (function tryNext(i){
      if(i >= cmds.length) return reject(new Error('All decrypt attempts failed. Last: ' + lastErr));
      const cmd = cmds[i];
      exec(cmd + ' 2>&1', { timeout: 120000 }, (err, stdout, stderr) => {
        lastErr = (stdout || '') + '\n' + (stderr || '') + (err ? ('\nERR:' + err.message) : '');
        if(!err && fs.existsSync(tmpOut) && fs.statSync(tmpOut).size > 0) {
          return resolve();
        }
        tryNext(i+1);
      });
    })(0);
  });
}

app.post('/decrypt', upload.single('file'), async (req, res) => {
  try {
    if (!req.file) return res.status(400).json({ success: false, error: 'Missing file' });
    const password = String(req.body.password || '');
    const tmpIn = req.file.path;
    const tmpOut = path.join(os.tmpdir(), 'msoff_out_' + Date.now() + '.xlsx');

    await tryCommands(tmpIn, tmpOut, password);

    const data = fs.readFileSync(tmpOut);
    const b64 = data.toString('base64');

    // cleanup
    try { fs.unlinkSync(tmpIn); } catch(e){}
    try { fs.unlinkSync(tmpOut); } catch(e){}

    res.json({ success: true, filename: req.file.originalname, data: b64 });
  } catch (e) {
    try { if (req.file && req.file.path) fs.unlinkSync(req.file.path); } catch(_){}
    res.status(500).json({ success: false, error: String(e.message || e) });
  }
});

// Health endpoint so the browser can probe server status
app.get('/health', (req, res) => {
  res.json({ ok: true, time: Date.now() });
});

const port = process.env.PORT || 3000;
app.listen(port, () => console.log(`decrypt-server listening on http://localhost:${port}`));

// Minimal usage notes printed when run directly
if (require.main === module) {
  console.log('Run with: node decrypt-server.js');
}
