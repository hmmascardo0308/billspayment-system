<script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof $ !== "undefined" && $.fn.select2) {
            console.log("Initializing Select2 for partner dropdown");
            $("#companyDropdown").select2({
                placeholder: "Search or select a company...",
                allowClear: true,
                width: "100%",
                minimumResultsForSearch: 0,
                dropdownParent: $("#companyDropdown").parent()
            });
        } else {
            console.error("jQuery or Select2 library not loaded");
        }
    });

    $(document).ready(function() {
        // Wait for DOM to be fully loaded
        setTimeout(function() {
            // Initialize Select2 for company dropdown
            $('#companyDropdown').select2({
                placeholder: "Search or select a company...",
                allowClear: true,
                width: '100%',
                minimumResultsForSearch: 0,
                dropdownParent: $('body'),
                theme: 'default',
                language: {
                    noResults: function() {
                        return "No partner found with that name";
                    },
                    searching: function() {
                        return "Searching...";
                    },
                    inputTooShort: function() {
                        return "Please enter at least 1 character";
                    }
                },
                escapeMarkup: function(markup) {
                    return markup;
                },
                templateResult: function(data) {
                    if (data.loading) return data.text;
                    return data.text;
                },
                templateSelection: function(data) {
                    return data.text;
                }
            });
            
            // Force refresh after initialization
            $('#companyDropdown').trigger('change.select2');
        }, 100);

        // Add change event handler for company dropdown
        $('#companyDropdown').on('change', function() {
            var selectedValue = $(this).val();
            console.log('Selected partner:', selectedValue);
            
            if (selectedValue) {
                console.log('Partner selected:', selectedValue);
            }
        });

        // Enhanced form validation
        $('form').on('submit', function(e) {
            var selectedCompany = $('#companyDropdown').val();
            var fileType = $('#fileType').val();
            var fileInput = $('#import_file')[0];
            
            // Validate company selection
            if (!selectedCompany) {
                e.preventDefault();
                Swal.fire({
                    title: 'Missing Partner',
                    text: 'Please select a partner company.',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            // Validate source file type is selected
            if (!fileType) {
                e.preventDefault();
                Swal.fire({
                    title: 'Missing File Type',
                    text: 'Please select a source file type (KPX or KP7).',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            // Validate file selection
            if (!fileInput.files.length) {
                e.preventDefault();
                Swal.fire({
                    title: 'Missing File',
                    text: 'Please select a file to import.',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            // Show loading overlay
            document.getElementById('loading-overlay').style.display = 'block';
        });
    });
</script>
<script>
    function debugInfo() {
        // Get table statistics
        const tableBody = document.getElementById('tableBody');
        const allRows = tableBody ? Array.from(tableBody.querySelectorAll('tr:not(.no-data-row)')) : [];
        const visibleRows = allRows.filter(row => row.style.display !== 'none');
        
        // Get filter values
        const statusFilter = document.getElementById('statusFilter');
        const partnerFilter = document.getElementById('partnerFilter');
        const searchInput = document.getElementById('tableSearch');
        
        // Get current filter states
        const currentStatusFilter = statusFilter ? statusFilter.options[statusFilter.selectedIndex].text : 'All Status';
        const currentPartnerFilter = partnerFilter ? partnerFilter.options[partnerFilter.selectedIndex].text : 'All Partners';
        const currentSearchTerm = searchInput ? searchInput.value || 'None' : 'None';
        
        // Get current date
        const currentDate = new Date().toLocaleDateString();
        
        // Get pagination info
        const totalPages = Math.ceil(filteredData.length / rowsPerPage);
        const currentPageInfo = `${currentPage} of ${totalPages}`;
        
        // Create session data object following the same structure
        const sessionData = {
            transactionDate: currentDate,
            fileName: 'Bills Payment Data',
            fileType: 'Database Query',
            selectedRegion: currentStatusFilter,
            selectedBranch: currentPartnerFilter,
            searchTerm: currentSearchTerm,
            totalRecords: allRows.length,
            visibleRecords: visibleRows.length,
            currentPage: currentPageInfo
        };
        
        Swal.fire({
            title: 'Bills Payment Details',
            html: `
                <div style="text-align: left;">
                    <p><strong>Import Date:</strong> ${sessionData.transactionDate}</p>
                    <p><strong>File Name:</strong> ${sessionData.fileName}</p>
                    <p><strong>Source Type:</strong> ${sessionData.fileType}</p>
                    <p><strong>Status Filter:</strong> ${sessionData.selectedRegion}</p>
                    <p><strong>Partner Filter:</strong> ${sessionData.selectedBranch}</p>
                    <p><strong>Search Term:</strong> ${sessionData.searchTerm}</p>
                    <p><strong>Total Records:</strong> ${sessionData.totalRecords}</p>
                    <p><strong>Visible Records:</strong> ${sessionData.visibleRecords}</p>
                    <p><strong>Current Page:</strong> ${sessionData.currentPage}</p>
                </div>
            `,
            icon: 'info',
            width: 600
        });
    }
</script>

<script>
    document.getElementById('export-pdf').addEventListener('click', function () {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export_pdf.php';
        document.body.appendChild(form);
        form.submit();
    });
</script>

<script type="text/javascript">

    document.querySelector('.form').addEventListener('submit', function(event) {
        // Get the file input element
        var fileInput = document.querySelector('input[type="file"]');
        var file = fileInput.files[0];

        // Check if a file is selected
        if (file) {
            // Display the progress modal
            var progressModal = document.querySelector('.progress_modal-dialog');
            progressModal.style.display = 'block';

            // Create a FormData object to send the file data
            var formData = new FormData();
            formData.append('import_file', file);

            // Create an XMLHttpRequest object
            var xhr = new XMLHttpRequest();

            // Set up the progress event listener
            xhr.upload.addEventListener('progress', function(event) {
                if (event.lengthComputable) {
                    // Calculate the progress percentage
                    var progress = (event.loaded / event.total) * 100;
                    updateProgressBar(progress); // Update the progress bar

                    // Check if the import is complete
                    if (progress === 100) {
                        // Import is complete, perform any additional actions here
                    }
                }
            });

            // Set up the load event listener
            xhr.addEventListener('load', function() {
                // Import is complete, perform any additional actions here
            });

            // Set up the error event listener
            xhr.addEventListener('error', function() {
                // An error occurred during the import
            });

            // Open a POST request to the server-side script
            xhr.open('POST', 'billsCode.php', true);

            // Send the form data
            xhr.send(formData);

            // Replace the progress bar with a loading message
            updateProgressBar('Loading...');
        }
    });
    
    function updateProgressBar(progress) {
        var progressBar = document.getElementById('progressBar');
        progressBar.innerHTML = progress;
    }

    // Enhanced file input with drag and drop functionality
    document.addEventListener('DOMContentLoaded', function() {
        const fileInput = document.getElementById('import_file');
        const fileInputWrapper = document.querySelector('.file-input-wrapper');
        const fileInputLabel = document.querySelector('.file-input-label');
        const fileName = document.querySelector('.file-name');
        const fileText = document.querySelector('.file-text');
        
        // Store the previous file to prevent duplicate events
        let previousFile = null;

        // File input change event - only trigger if file actually changed
        fileInput.addEventListener('change', function(e) {
            const currentFile = e.target.files[0];
            
            // Only handle if file is different from previous or if it's a new file selection
            if (currentFile !== previousFile) {
                handleFileSelection(currentFile);
                previousFile = currentFile;
            }
        });

        // Drag and drop events
        fileInputWrapper.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileInputWrapper.classList.add('drag-over');
            fileInputLabel.style.backgroundColor = '#e3f2fd';
            fileInputLabel.style.borderColor = '#2196f3';
        });

        fileInputWrapper.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileInputWrapper.classList.remove('drag-over');
            fileInputLabel.style.backgroundColor = '#f8f9fa';
            fileInputLabel.style.borderColor = '#ddd';
        });

        fileInputWrapper.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileInputWrapper.classList.remove('drag-over');
            fileInputLabel.style.backgroundColor = '#f8f9fa';
            fileInputLabel.style.borderColor = '#ddd';

            const files = e.dataTransfer.files;
            if (files.length > 0) {
                // Create a new FileList and assign it to the input
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                fileInput.files = dt.files;
                
                handleFileSelection(files[0]);
                previousFile = files[0];
            }
        });

        // Function to handle file selection
        function handleFileSelection(file) {
            const fileName = document.querySelector('.file-name');
            const fileText = document.querySelector('.file-text');
            
            if (file) {
                // Truncate filename if too long
                let displayName = file.name;
                if (displayName.length > 30) {
                    const extension = displayName.substring(displayName.lastIndexOf('.'));
                    const nameWithoutExt = displayName.substring(0, displayName.lastIndexOf('.'));
                    displayName = nameWithoutExt.substring(0, 20) + '...' + extension;
                }
                
                fileName.textContent = displayName;
                fileName.title = file.name; // Show full name on hover
                fileText.textContent = 'File Selected';
                fileInputWrapper.classList.add('file-selected');
                
                // Add success styling
                fileInputLabel.style.backgroundColor = '#d4edda';
                fileInputLabel.style.borderColor = '#28a745';
                fileInputLabel.style.color = '#155724';
                
                // Add checkmark icon
                const icon = fileInputLabel.querySelector('i');
                if (icon) {
                    icon.className = 'fa-solid fa-check';
                }
            } else {
                fileName.textContent = 'No file chosen';
                fileName.title = '';
                fileText.textContent = 'Choose File';
                fileInputWrapper.classList.remove('file-selected');
                
                // Reset styling
                fileInputLabel.style.backgroundColor = '#f8f9fa';
                fileInputLabel.style.borderColor = '#ddd';
                fileInputLabel.style.color = '#333';
                
                // Reset icon
                const icon = fileInputLabel.querySelector('i');
                if (icon) {
                    icon.className = 'fa-solid fa-upload';
                }
            }
        }

        // Click to browse functionality - prevent double triggering
        fileInputLabel.addEventListener('click', function(e) {
            e.preventDefault();
            fileInput.click();
        });

        // Prevent default drag behaviors on document
        document.addEventListener('dragover', function(e) {
            e.preventDefault();
        });

        document.addEventListener('drop', function(e) {
            e.preventDefault();
        });

        // JavaScript code for messageModal
        var modal = document.getElementById('messageModal');
        var closeButton = document.querySelector('.message_close');
        var modalMessage = document.getElementById('modalMessage');

        // Check if the show-modal flag is set in session
        if (<?php echo isset($_SESSION['show-modal']) ? 'true' : 'false'; ?>) {
            // Display the modal
            modal.style.display = 'block';
            
            // Close the modal when the close button is clicked
            closeButton.addEventListener('click', function() {
                modal.style.display = 'none';
            });
            
            // Remove the show-modal flag from session
            <?php unset($_SESSION['show-modal']); ?>
        }
    });
    
</script>

<script>
   // Hide and Show Side Menu
var menubtn = document.getElementById("menu-btn"); // Menu Button
var sidemenu = document.getElementById("sidemenu"); // Side Menu Div

// Add a click event listener to the document object
document.addEventListener("click", function(event) {
    // Check if the clicked element is outside of the sidemenu and is not the button
    if (!sidemenu.contains(event.target) && event.target !== menubtn) {
        // Hide the sidemenu
        sidemenu.style.animation = "slide-out-to-left 0.5s ease";
        setTimeout(function() {
            sidemenu.style.display = "none";
        }, 450);
    }
});

menubtn.addEventListener("click", function(){
    if(sidemenu.style.display == "none" || sidemenu.style.display == ""){
        sidemenu.style.animation = "slide-in-from-left 0.5s ease";
        sidemenu.style.display = "block";
    }else{
        sidemenu.style.animation = "slide-out-to-left 0.5s ease";
        setTimeout(function() {
            sidemenu.style.display = "none";
        }, 450);
    }
});

// Get all the elements (with null checks)
var parabtn = document.getElementById("para-btn"); // Main Para Button
var paraopen = document.getElementById("open-para"); // Para Div Down Arrow or Expanded
var paraclosed = document.getElementById("closed-para"); // Para Div Right Arrow or Minimized
var paraimportnav = document.getElementById("para-import-nav"); // Para Import Div
var parareportnav = document.getElementById("para-report-nav"); // Para Report Div
var paraimportbtn = document.getElementById("para-import-btn"); // Para Import Btn
var parareportbtn = document.getElementById("para-report-btn"); // Para Report Btn
var actionreportbtn = document.getElementById("action-report-btn"); // Action Report Btn
var actionreportnav = document.getElementById("action-report-nav"); // Action Report Div

// Sub-elements
var paraopenimport = document.getElementById("open-para-import"); // Para Import Div Down Arrow or Expanded
var paraclosedimport = document.getElementById("closed-para-import"); // Para Import Div Right Arrow or Minimized
var paraopenreport = document.getElementById("open-para-report"); // Para Report Div Down Arrow or Expanded
var paraclosedreport = document.getElementById("closed-para-report"); // Para Report Div Right Arrow or Minimized
var actionopenreport = document.getElementById("open-action-report"); // Action Report Div Down Arrow or Expanded
var actionclosedreport = document.getElementById("closed-action-report"); // Action Report Div Right Arrow or Minimized

// Initialize all dropdown states
function initializeDropdowns() {
    // Set initial states for all elements
    if (paraimportbtn) paraimportbtn.style.display = "none";
    if (parareportbtn) parareportbtn.style.display = "none";
    if (actionreportbtn) actionreportbtn.style.display = "none";
    if (paraimportnav) paraimportnav.style.display = "none";
    if (parareportnav) parareportnav.style.display = "none";
    if (actionreportnav) actionreportnav.style.display = "none";
    
    // Set arrow states
    if (paraopen) paraopen.style.display = "none";
    if (paraclosed) paraclosed.style.display = "block";
    if (paraopenimport) paraopenimport.style.display = "none";
    if (paraclosedimport) paraclosedimport.style.display = "block";
    if (paraopenreport) paraopenreport.style.display = "none";
    if (paraclosedreport) paraclosedreport.style.display = "block";
    if (actionopenreport) actionopenreport.style.display = "none";
    if (actionclosedreport) actionclosedreport.style.display = "block";
}

// Call initialization when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeDropdowns();
});

// Main Billspayment dropdown handler
if (parabtn) {
    parabtn.addEventListener("click", function(){ 
        // Check if sub-menus are currently hidden
        var isHidden = !paraimportbtn || paraimportbtn.style.display === "none" || paraimportbtn.style.display === "";
        
        if (isHidden) {
            // Show sub-menus
            if (paraimportbtn) {
                paraimportbtn.style.animation = "slide-in-from-top 0.8s ease";
                paraimportbtn.style.display = "flex";
            }
            if (parareportbtn) {
                parareportbtn.style.animation = "slide-in-from-top 0.8s ease";
                parareportbtn.style.display = "flex";
            }
            if (actionreportbtn) {
                actionreportbtn.style.animation = "slide-in-from-top 0.8s ease";
                actionreportbtn.style.display = "flex";
            }
            
            // Update arrows
            if (paraopen) paraopen.style.display = "block";       
            if (paraclosed) paraclosed.style.display = "none";
            
        } else {
            // Hide all sub-menus and their children
            if (paraopen) paraopen.style.display = "none";
            if (paraclosed) paraclosed.style.display = "block";
            
            // Hide child menus first
            if (paraimportnav) paraimportnav.style.display = "none";
            if (parareportnav) parareportnav.style.display = "none";
            if (actionreportnav) actionreportnav.style.display = "none";
            
            // Reset child arrows
            if (paraopenimport) paraopenimport.style.display = "none";
            if (paraclosedimport) paraclosedimport.style.display = "block";
            if (paraopenreport) paraopenreport.style.display = "none";
            if (paraclosedreport) paraclosedreport.style.display = "block";
            if (actionopenreport) actionopenreport.style.display = "none";
            if (actionclosedreport) actionclosedreport.style.display = "block";
            
            // Animate out parent menus
            if (paraimportbtn) paraimportbtn.style.animation = "slide-out-to-top 0.5s ease";
            if (parareportbtn) parareportbtn.style.animation = "slide-out-to-top 0.5s ease";
            if (actionreportbtn) actionreportbtn.style.animation = "slide-out-to-top 0.5s ease";
            
            setTimeout(function() {
                if (paraimportbtn) paraimportbtn.style.display = "none";
                if (parareportbtn) parareportbtn.style.display = "none";
                if (actionreportbtn) actionreportbtn.style.display = "none";
            }, 450);
        }
    });
}

// Billspayment Import dropdown handler
if (paraimportbtn) {
    paraimportbtn.addEventListener("click", function(){ 
        var isHidden = !paraimportnav || paraimportnav.style.display === "none" || paraimportnav.style.display === "";
        
        if (isHidden) {
            if (paraimportnav) {
                paraimportnav.style.animation = "slide-in-from-top 0.8s ease";
                paraimportnav.style.display = "block";
            }
            if (paraopenimport) paraopenimport.style.display = "block";
            if (paraclosedimport) paraclosedimport.style.display = "none";
        } else {
            if (paraopenimport) paraopenimport.style.display = "none";
            if (paraclosedimport) paraclosedimport.style.display = "block";
            if (paraimportnav) {
                paraimportnav.style.animation = "slide-out-to-top 0.5s ease";
                setTimeout(function() {
                    paraimportnav.style.display = "none";
                }, 450);
            }
        }
    });
}

// Billspayment Report dropdown handler
if (parareportbtn) {
    parareportbtn.addEventListener("click", function(){ 
        var isHidden = !parareportnav || parareportnav.style.display === "none" || parareportnav.style.display === "";
        
        if (isHidden) {
            if (parareportnav) {
                parareportnav.style.animation = "slide-in-from-top 0.8s ease";
                parareportnav.style.display = "block";
            }
            if (paraopenreport) paraopenreport.style.display = "block";
            if (paraclosedreport) paraclosedreport.style.display = "none";
        } else {
            if (parareportnav) {
                parareportnav.style.animation = "slide-out-to-top 0.5s ease";
                setTimeout(function() {
                    parareportnav.style.display = "none";
                }, 450);
            }
            if (paraopenreport) paraopenreport.style.display = "none";
            if (paraclosedreport) paraclosedreport.style.display = "block";
        }
    });
}

// Action Report dropdown handler
if (actionreportbtn) {
    actionreportbtn.addEventListener("click", function(){ 
        var isHidden = !actionreportnav || actionreportnav.style.display === "none" || actionreportnav.style.display === "";
        
        if (isHidden) {
            if (actionreportnav) {
                actionreportnav.style.animation = "slide-in-from-top 0.8s ease";
                actionreportnav.style.display = "block";
            }
            if (actionopenreport) actionopenreport.style.display = "block";
            if (actionclosedreport) actionclosedreport.style.display = "none";
        } else {
            if (actionreportnav) {
                actionreportnav.style.animation = "slide-out-to-top 0.5s ease";
                setTimeout(function() {
                    actionreportnav.style.display = "none";
                }, 450);
            }
            if (actionopenreport) actionopenreport.style.display = "none";
            if (actionclosedreport) actionclosedreport.style.display = "block";
        }
    });
}

// MAA (Bookkeeper) dropdown handler
var maabtn = document.getElementById("maa-btn");
var maaopen = document.getElementById("open-maa");
var maaclosed = document.getElementById("closed-maa");
var maanav = document.getElementById("maa-nav");

if (maabtn && maaopen && maaclosed && maanav) {
    // Initialize MAA dropdown
    maanav.style.display = "none";
    maaopen.style.display = "none";
    maaclosed.style.display = "block";
    
    maabtn.addEventListener("click", function(){
        var isHidden = maanav.style.display === "none" || maanav.style.display === "";
        
        if (isHidden) {
            maaopen.style.display = "block";
            maaclosed.style.display = "none";
            maanav.style.display = "block";
            maanav.style.animation = "slide-in-from-top 0.8s ease";
        } else {
            maanav.style.animation = "slide-out-to-top 0.5s ease";
            setTimeout(function() {
                maanav.style.display = "none";
            }, 450);
            maaopen.style.display = "none";
            maaclosed.style.display = "block";
        }
    });
}

// Additional handlers for other menu items (GLE, MSTRFL, RECON) if they exist
var glebtn = document.getElementById("gle-btn");
var gleopen = document.getElementById("open-gle");
var gleclosed = document.getElementById("closed-gle");
var glenav = document.getElementById("gle-nav");

if (glebtn && gleopen && gleclosed && glenav) {
    glenav.style.display = "none";
    gleopen.style.display = "none";
    gleclosed.style.display = "block";
    
    glebtn.addEventListener("click", function(){
        var isHidden = glenav.style.display === "none" || glenav.style.display === "";
        
        if (isHidden) {
            glenav.style.animation = "slide-in-from-top 0.8s ease";
            gleopen.style.display = "block";
            gleclosed.style.display = "none";
            glenav.style.display = "block";
        } else {
            gleopen.style.display = "none";
            gleclosed.style.display = "block";
            glenav.style.animation = "slide-out-to-top 0.5s ease";
            setTimeout(function() {
                glenav.style.display = "none";
            }, 450);
        }
    });
}

var mstrfl = document.getElementById("mstrfl-btn");
var mstrflopen = document.getElementById("open-mstrfl");
var mstrflclosed = document.getElementById("closed-mstrfl");
var mstrflnav = document.getElementById("mstrfl-nav");

if (mstrfl && mstrflopen && mstrflclosed && mstrflnav) {
    mstrflnav.style.display = "none";
    mstrflopen.style.display = "none";
    mstrflclosed.style.display = "block";
    
    mstrfl.addEventListener("click", function(){
        var isHidden = mstrflnav.style.display === "none" || mstrflnav.style.display === "";
        
        if (isHidden) {
            mstrflnav.style.animation = "slide-in-from-top 0.8s ease";
            mstrflopen.style.display = "block";
            mstrflclosed.style.display = "none";
            mstrflnav.style.display = "block";
        } else {
            mstrflnav.style.animation = "slide-out-to-top 0.5s ease";
            setTimeout(function() {
                mstrflnav.style.display = "none";
            }, 450);
            mstrflopen.style.display = "none";
            mstrflclosed.style.display = "block";
        }
    });
}

var recon = document.getElementById("recon-btn");
var reconopen = document.getElementById("open-recon");
var reconclosed = document.getElementById("closed-recon");
var reconnav = document.getElementById("recon-nav");

if (recon && reconopen && reconclosed && reconnav) {
    reconnav.style.display = "none";
    reconopen.style.display = "none";
    reconclosed.style.display = "block";
    
    recon.addEventListener("click", function(){
        var isHidden = reconnav.style.display === "none" || reconnav.style.display === "";
        
        if (isHidden) {
            reconnav.style.animation = "slide-in-from-top 0.8s ease";
            reconopen.style.display = "block";
            reconclosed.style.display = "none";
            reconnav.style.display = "block";
        } else {
            reconnav.style.animation = "slide-out-to-top 0.5s ease";
            setTimeout(function() {
                reconnav.style.display = "none";
            }, 450);
            reconopen.style.display = "none";
            reconclosed.style.display = "block";
        }
    });
}
   
</script>

<script>
// Table functionality
let currentPage = 1;
let rowsPerPage = 50;
let filteredData = [];

document.addEventListener('DOMContentLoaded', function() {
    // Initialize table
    initializeTable();
    
    // Search functionality
    document.getElementById('tableSearch').addEventListener('input', function() {
        filterTable();
    });
    
    // Filter functionality
    document.getElementById('statusFilter').addEventListener('change', function() {
        filterTable();
    });
    
    document.getElementById('partnerFilter').addEventListener('change', function() {
        filterTable();
    });
});

function initializeTable() {
    const tableBody = document.getElementById('tableBody');
    const rows = Array.from(tableBody.querySelectorAll('tr'));
    filteredData = rows;
    updatePagination();
}

function filterTable() {
    const searchTerm = document.getElementById('tableSearch').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value;
    const partnerFilter = document.getElementById('partnerFilter').value;
    
    const tableBody = document.getElementById('tableBody');
    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    
    filteredData = allRows.filter(row => {
        if (row.querySelector('.no-data')) return false;
        
        const rowText = row.textContent.toLowerCase();
        const rowStatus = row.getAttribute('data-status');
        const rowPartner = row.getAttribute('data-partner');
        
        const matchesSearch = rowText.includes(searchTerm);
        const matchesStatus = !statusFilter || rowStatus === statusFilter || (statusFilter === 'normal' && !['*', '**', '***'].includes(rowStatus));
        const matchesPartner = !partnerFilter || rowPartner === partnerFilter;
        
        return matchesSearch && matchesStatus && matchesPartner;
    });
    
    currentPage = 1;
    displayTable();
    updatePagination();
}

function displayTable() {
    const tableBody = document.getElementById('tableBody');
    const startIndex = (currentPage - 1) * rowsPerPage;
    const endIndex = startIndex + rowsPerPage;
    
    // Hide all rows
    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    allRows.forEach(row => row.style.display = 'none');
    
    // Show filtered rows for current page
    const rowsToShow = filteredData.slice(startIndex, endIndex);
    rowsToShow.forEach(row => row.style.display = '');
    
    // Show no data message if no results
    if (filteredData.length === 0) {
        if (!document.querySelector('.no-data-row')) {
            const noDataRow = document.createElement('tr');
            noDataRow.className = 'no-data-row';
            noDataRow.innerHTML = '<td colspan="9" class="no-data">No data matches your search criteria</td>';
            tableBody.appendChild(noDataRow);
        }
    } else {
        const noDataRow = document.querySelector('.no-data-row');
        if (noDataRow) {
            noDataRow.remove();
        }
    }
    
    updatePaginationInfo();
}

function updatePagination() {
    const totalPages = Math.ceil(filteredData.length / rowsPerPage);
    
    document.getElementById('prevBtn').disabled = currentPage === 1;
    document.getElementById('nextBtn').disabled = currentPage === totalPages || totalPages === 0;
    
    // Update page numbers
    const pageNumbers = document.getElementById('pageNumbers');
    pageNumbers.innerHTML = '';
    
    for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
        const pageBtn = document.createElement('button');
        pageBtn.className = 'pagination-btn' + (i === currentPage ? ' active' : '');
        pageBtn.textContent = i;
        pageBtn.onclick = () => goToPage(i);
        pageNumbers.appendChild(pageBtn);
    }
    
    displayTable();
}

function updatePaginationInfo() {
    const start = filteredData.length === 0 ? 0 : (currentPage - 1) * rowsPerPage + 1;
    const end = Math.min(currentPage * rowsPerPage, filteredData.length);
    const total = filteredData.length;
    
    document.getElementById('paginationInfo').textContent = 
        `Showing ${start}-${end} of ${total} entries`;
}

function changePage(direction) {
    const totalPages = Math.ceil(filteredData.length / rowsPerPage);
    const newPage = currentPage + direction;
    
    if (newPage >= 1 && newPage <= totalPages) {
        currentPage = newPage;
        updatePagination();
    }
}

function goToPage(page) {
    currentPage = page;
    updatePagination();
}

function refreshTable() {
    location.reload();
}

function exportTable() {
    // Create CSV content
    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "ID,Partner,Transaction Date,Reference Number,Amount Paid,Charge to Partner,Charge to Customer,Status\n";
    
    filteredData.forEach(row => {
        const cells = Array.from(row.querySelectorAll('td')).slice(0, 8); // Exclude actions column
        const rowData = cells.map(cell => {
            let text = cell.textContent.trim();
            // Remove currency symbols and format numbers
            if (cell.classList.contains('amount')) {
                text = text.replace(/[^\d.-]/g, '');
            }
            return `"${text}"`;
        }).join(',');
        csvContent += rowData + "\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "bills_payment_data.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function viewTransaction(id) {
    // Implementation for viewing transaction details
    alert(`Viewing transaction ID: ${id}`);
}

function editTransaction(id) {
    // Implementation for editing transaction
    alert(`Editing transaction ID: ${id}`);
}
</script>