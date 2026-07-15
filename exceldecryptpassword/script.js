const fileInput = document.getElementById("excelFiles");
const processBtn = document.getElementById("processBtn");
const clearBtn = document.getElementById("clearBtn");
const bulkDownloadBtn = document.getElementById("bulkDownloadBtn");
const fileListBody = document.getElementById("fileListBody");
const summary = document.getElementById("summary");
const dropZone = document.getElementById("dropZone");

const fileItems = [];
let activePassword = "";

fileInput.addEventListener("change", onFilesSelected);
processBtn.addEventListener("click", processAllFiles);
clearBtn.addEventListener("click", clearAllData);
bulkDownloadBtn.addEventListener("click", downloadBulkZip);
fileListBody.addEventListener("click", onTableClick);
dropZone.addEventListener("dragover", onDragOver);
dropZone.addEventListener("dragleave", onDragLeave);
dropZone.addEventListener("drop", onDropFiles);
dropZone.addEventListener("click", () => fileInput.click());
dropZone.addEventListener("keydown", onDropZoneKeyDown);

bulkDownloadBtn.style.display = "none";

function onFilesSelected(event) {
  setSelectedFiles(Array.from(event.target.files || []));
}

function setSelectedFiles(pickedFiles) {

  if (pickedFiles.length === 0) {
    return;
  }

  fileItems.length = 0;
  bulkDownloadBtn.style.display = "none";

  pickedFiles.forEach((file) => {
    const ext = getExtension(file.name);
    const isValid = ext === "xls" || ext === "xlsx";

    fileItems.push({
      file,
      ext,
      status: isValid ? "pending" : "error",
      errorMessage: isValid ? "" : "Unsupported extension",
      downloadName: buildOutputName(file.name, ext),
      serverReady: false
    });
  });

  updateSummary();
  renderTable();
}

function onDragOver(event) {
  event.preventDefault();
  dropZone.classList.add("dragover");
}

function onDragLeave(event) {
  if (event.currentTarget.contains(event.relatedTarget)) {
    return;
  }
  dropZone.classList.remove("dragover");
}

function onDropFiles(event) {
  event.preventDefault();
  dropZone.classList.remove("dragover");

  const droppedFiles = Array.from(event.dataTransfer?.files || []);
  if (droppedFiles.length === 0) {
    return;
  }

  const dataTransfer = new DataTransfer();
  droppedFiles.forEach((file) => dataTransfer.items.add(file));
  fileInput.files = dataTransfer.files;

  setSelectedFiles(droppedFiles);
}

function onDropZoneKeyDown(event) {
  if (event.key !== "Enter" && event.key !== " ") {
    return;
  }

  event.preventDefault();
  fileInput.click();
}

function clearAllData() {
  fileItems.length = 0;
  activePassword = "";
  fileInput.value = "";
  bulkDownloadBtn.style.display = "none";
  bulkDownloadBtn.disabled = false;
  processBtn.disabled = false;

  summary.textContent = "No files selected.";
  fileListBody.innerHTML = '<tr><td colspan="4" class="empty-row">Upload files to begin.</td></tr>';
}

async function processAllFiles() {
  if (fileItems.length === 0) {
    alert("Please select one or more Excel files first.");
    return;
  }

  const enteredPassword = window.prompt("Enter Excel password:", activePassword || "");
  if (enteredPassword === null) {
    return;
  }

  const normalizedPassword = enteredPassword.trim();
  if (!normalizedPassword) {
    alert("Password is required.");
    return;
  }

  activePassword = normalizedPassword;

  fileItems.forEach((item) => {
    if (item.ext === "xls" || item.ext === "xlsx") {
      item.status = "pending";
      item.errorMessage = "";
      item.serverReady = true;
    }
  });

  updateSummary();
  renderTable();

  const hasValidExcel = fileItems.some((item) => item.ext === "xls" || item.ext === "xlsx");
  bulkDownloadBtn.style.display = hasValidExcel ? "inline-flex" : "none";
}

async function onTableClick(event) {
  const trigger = event.target.closest("button[data-action='single-download']");
  if (!trigger) {
    return;
  }

  const index = Number(trigger.getAttribute("data-index"));
  if (!Number.isInteger(index) || !fileItems[index]) {
    return;
  }

  await downloadSingleFile(index, trigger);
}

async function downloadSingleFile(index, triggerBtn) {
  const item = fileItems[index];
  if (item.status === "error") {
    return;
  }

  triggerBtn.disabled = true;
  item.status = "processing";
  item.errorMessage = "";
  updateSummary();
  renderTable();

  try {
    if (!activePassword) {
      throw new Error("Please click Process Files and provide password first");
    }

    const formData = new FormData();
    formData.append("excelFile", item.file, item.file.name);
    formData.append("password", activePassword);

    const response = await fetch("download_single.php", {
      method: "POST",
      body: formData
    });

    if (!response.ok) {
      throw new Error(await safeReadError(response, "Single download failed"));
    }

    const blob = await response.blob();
    triggerBlobDownload(blob, item.downloadName || buildOutputName(item.file.name, item.ext));
    item.status = "done";
  } catch (error) {
    item.status = "error";
    item.errorMessage = extractErrorMessage(error);
  }

  updateSummary();
  renderTable();
}

async function downloadBulkZip() {
  const validItems = fileItems.filter((item) => item.ext === "xls" || item.ext === "xlsx");
  if (validItems.length === 0) {
    alert("Please select one or more valid Excel files first.");
    return;
  }

  bulkDownloadBtn.disabled = true;
  validItems.forEach((item) => {
    item.status = "processing";
    item.errorMessage = "";
  });
  updateSummary();
  renderTable();

  try {
    if (!activePassword) {
      throw new Error("Please click Process Files and provide password first");
    }

    const formData = new FormData();
    validItems.forEach((item) => formData.append("excelFiles[]", item.file, item.file.name));
    formData.append("password", activePassword);

    const response = await fetch("download_bulk.php", {
      method: "POST",
      body: formData
    });

    if (!response.ok) {
      throw new Error(await safeReadError(response, "Bulk download failed"));
    }

    const blob = await response.blob();
    triggerBlobDownload(blob, `decrypted_excels_${formatNowForFileName()}.zip`);
    validItems.forEach((item) => {
      item.status = "done";
    });
  } catch (error) {
    validItems.forEach((item) => {
      item.status = "error";
      item.errorMessage = extractErrorMessage(error);
    });
  } finally {
    bulkDownloadBtn.disabled = false;
    updateSummary();
    renderTable();
  }
}

function renderTable() {
  if (fileItems.length === 0) {
    fileListBody.innerHTML = '<tr><td colspan="4" class="empty-row">Upload files to begin.</td></tr>';
    return;
  }

  fileListBody.innerHTML = fileItems
    .map((item, index) => {
      const statusLabel = item.status === "error"
        ? `error${item.errorMessage ? `: ${escapeHtml(item.errorMessage)}` : ""}`
        : item.status;

      return `
        <tr>
          <td>${escapeHtml(item.file.name)}</td>
          <td>${escapeHtml(item.ext || "n/a")}</td>
          <td>
            <span class="status-pill status-${item.status}">${statusLabel}</span>
          </td>
          <td>${renderActionButton(item, index)}</td>
        </tr>
      `;
    })
    .join("");
}

function renderActionButton(item, index) {
  const isSupported = item.ext === "xls" || item.ext === "xlsx";
  if (!isSupported) {
    return '<span class="download-btn" aria-disabled="true">Download</span>';
  }

  if (!item.serverReady) {
    return '<span class="download-btn" aria-disabled="true">Download</span>';
  }

  const disabledAttr = item.status === "processing" ? "disabled" : "";
  const label = item.status === "processing" ? "Downloading..." : "Download";
  return `<button type="button" class="download-btn" data-action="single-download" data-index="${index}" ${disabledAttr}>${label}</button>`;
}

function updateSummary() {
  if (fileItems.length === 0) {
    summary.textContent = "No files selected.";
    return;
  }

  const doneCount = fileItems.filter((x) => x.status === "done").length;
  const errorCount = fileItems.filter((x) => x.status === "error").length;
  const processingCount = fileItems.filter((x) => x.status === "processing").length;
  const pendingCount = fileItems.filter((x) => x.status === "pending").length;

  summary.textContent = `Total: ${fileItems.length} | pending: ${pendingCount} | processing: ${processingCount} | done: ${doneCount} | error: ${errorCount}`;
}

function getExtension(filename) {
  const parts = filename.split(".");
  return parts.length > 1 ? parts.pop().toLowerCase() : "";
}

function buildOutputName(filename, ext) {
  const base = filename.replace(/\.[^.]+$/, "");
  return `${base}_decrypted.${ext}`;
}

function extractErrorMessage(error) {
  const message = (error && error.message) ? error.message : "Unable to decrypt";

  if (/password|decrypt|unsupported|encrypted/i.test(message)) {
    return message;
  }

  return "Failed to decrypt with fixed password";
}

function triggerBlobDownload(blob, filename) {
  const objectUrl = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = objectUrl;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(objectUrl);
}

async function safeReadError(response, fallbackMessage) {
  const contentType = response.headers.get("content-type") || "";

  if (contentType.includes("application/json")) {
    try {
      const payload = await response.json();
      return payload.error || fallbackMessage;
    } catch (error) {
      return fallbackMessage;
    }
  }

  try {
    const text = await response.text();
    return text || fallbackMessage;
  } catch (error) {
    return fallbackMessage;
  }
}

function formatNowForFileName() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, "0");
  const d = String(now.getDate()).padStart(2, "0");
  const hh = String(now.getHours()).padStart(2, "0");
  const mm = String(now.getMinutes()).padStart(2, "0");
  const ss = String(now.getSeconds()).padStart(2, "0");
  return `${y}${m}${d}_${hh}${mm}${ss}`;
}

function escapeHtml(text) {
  return String(text)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/\"/g, "&quot;")
    .replace(/'/g, "&#039;");
}
