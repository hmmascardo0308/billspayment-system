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