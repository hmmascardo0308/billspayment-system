<script>
    function showConfirmationDialog() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'Do you want to proceed with this action?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, proceed',
            cancelButtonText: 'No, cancel',
            reverseButtons: false,
            allowOutsideClick: false
        }).then((result) => {
            if (result.isConfirmed) {
                // User confirmed the action
                sendAjaxRequest();
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                // User canceled the action
                showCancellationMessage();
            }
        });
    }

    function sendAjaxRequest() {
        $.ajax({
            url:'',
            type: 'POST',
            data: { proceed: true }, // Send a flag indicating the user confirmed
            success: handleResponse,
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Oops!',
                    text: 'Something went wrong. Please try again later.',
                    confirmButtonText: 'Close',
                    allowOutsideClick: false
                });
            }
        });
    }

    function isJsonString(str) {
        try {
            JSON.parse(str);
        } catch (e) {
            return false;
        }
        return true;
    }

    function handleResponse(response) {
        if (isJsonString(response)) {
            var responseData = JSON.parse(response);
            if (responseData.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: responseData.message,
                    confirmButtonText: 'OK',
                    allowOutsideClick: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'billsFeedback.php?proceed=true';
                    }
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: responseData.message,
                    confirmButtonText: 'OK',
                    allowOutsideClick: false
                });
            }
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Unexpected server response. Please try again later.',
                confirmButtonText: 'OK',
                allowOutsideClick: false
            });
            console.error('Invalid JSON response:', response); //debugging output
        }
    }

    function showCancellationMessage() {
        Swal.fire({
            icon: 'info',
            title: 'Cancelled',
            text: 'Your action has been cancelled.',
            confirmButtonText: 'OK',
            allowOutsideClick: false
        });
    }
</script>

<style>
        /* for table */
        .table-container {
            position: relative;
            max-width: 100%;
            overflow-x: auto; /* Enable horizontal scrolling */
            overflow-y: auto; /* Enable vertical scrolling */
            max-height: calc(100vh - 215px);
            margin: 20px; 
        }
        .table-container1 {
            position: relative;
            max-width: 60%;
            overflow-x: auto; /* Enable horizontal scrolling */
            overflow-y: auto; /* Enable vertical scrolling */
            max-height: calc(100vh - 500px);
            margin: 20px; 
        }
        .table-container2 {
            position: relative;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: auto;
            max-height: calc(100vh - 500px);
            margin: 0;
            padding: 0;
            border-bottom: 1px solid #ddd;
            background-color: #fff; 
        }

        .tabcont {
            position: relative;
            max-width: 76%;
            max-height: calc(100vh - 500px);
            margin: 20px; 
        }

        .table-container-showcadno {
            left: 25%;
            position: relative;
            height: 200px;
            max-width: 50%;
            overflow-x: auto; /* Enable horizontal scrolling */
            overflow-y: auto; /* Enable vertical scrolling */
            max-height: calc(100vh - 200px);
            margin: 3px; 
        }

        .table-container-error {
            position: relative;
            max-width: 100%;
            overflow-x: auto; /* Enable horizontal scrolling */
            overflow-y: auto; /* Enable vertical scrolling */
            max-height: calc(100vh - 200px); 
            margin: 20px; 
        }
        .file-table {
            width: 100%;
            border-collapse: collapse;
            overflow: auto;
            max-height: 855px;
        }
        .file-table2 {
            width: 100%;
            border-collapse: collapse;
            overflow: auto;
            max-height: 500px
        }
        .file-table th, .file-table td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .file-table2 th, .file-table2 td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .file-table th {
            background-color: #f2f2f2;
        }
        .file-table2 th {
            background-color: #f2f2f2;
        }
        thead th {
            top: 0;
            position: sticky;
            z-index: 20;

        }
        .error-row {
            background-color: #ed968c;
        }
        #showEP {
            display: none;
        }
        .custom-select-wrapper {
            position: relative;
            display: inline-block;
            margin-left: 20px;
        }
        
        input[type="file"]::file-selector-button {
            border-radius: 15px;
            padding: 0 16px;
            height: 35px;
            cursor: pointer;
            background-color: white;
            border: 1px solid rgba(0, 0, 0, 0.16);
            box-shadow: 0px 1px 0px rgba(0, 0, 0, 0.05);
            margin-right: 16px;
            transition: background-color 200ms;
        }

        input[type="file"]::file-selector-button:hover {
            background-color: #f3f4f6;
        }

        input[type="file"]::file-selector-button:active {
            background-color: #e5e7eb;
        }

        input[type="file"]::file-selector-button:hover {
            background-color: #f3f4f6;
        }

        input[type="file"]::file-selector-button:active {
            background-color: #e5e7eb;
        }

        .upload-btn {
            background-color: #d70c0c;
            color: #fff;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #fff;
            border-top-right-radius: 10px;
            border-bottom-right-radius: 10px;
            width: 100px;
            margin-right: 25px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .upload-btn:hover {
            background-color:rgb(180, 31, 31);
        }
        /* loading screen (clean, centered, and consistent) */
        #loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.45);
            z-index: 99999;
            /* keep pointer-events enabled so overlay blocks interaction when visible */
            pointer-events: auto;
        }

        /* Use a fixed-position spinner so it always centers in the viewport
           even if some scripts set display:block on the overlay (legacy JS). */
        .loading-spinner {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 64px;
            height: 64px;
            border-radius: 12px;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 20px rgba(0,0,0,0.25);
            padding: 8px;
        }

        .loading-spinner:before {
            content: '';
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 4px solid #e9eef5;
            border-top-color: #c13232;
            animation: spin 1s linear infinite;
            display: block;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .usernav {
            display: flex;
            justify-content: left;
            align-items: center;
            font-size: 10px;
            font-weight: bold;
            margin: 0;
        }

        .nav-list {
            list-style: none;
            display: flex;
        }
        
        .nav-list li {
            margin-right: 20px;
        }
        
        .nav-list li a {
            text-decoration: none;
            color: #fff;
            font-size: 12px;
            font-weight: bold;
            padding: 5px 20px 5px 20px;
        }
        
        .nav-list li #user {
            text-decoration: none;
            color: #d70c0c;
            font-size: 12px;
            font-weight: bold;
            padding: 5px 20px 5px 20px;
        }
        
        .nav-list li a:hover {
            color: #d70c0c;
            background-color: whitesmoke;
        }
        
        .nav-list li #user:hover {
            color: #d70c0c;
        }

        .dropdown-btn {
            position: relative;
            display: inline-block;
            background-color: transparent;
            border: none;
            color: #fff;
            font-weight: 700;
            font-size: 12px;
            width: 150px;
            padding: 5px 20px 5px 20px;
            transition: background-color 0.3s ease;
        }
        
        .dropdown-btn:hover {
            position: relative;
            display: inline-block;
            background-color: whitesmoke;
            border: none;
            color: #d70c0c;
            width: 150px;
            font-weight: 700;
            font-size: 12px;
            padding: 5px;
            transition: background-color 0.3s ease;
        }
        .dropdown:hover .dropdown-content {
            display: block;
            z-index: 1;
            text-align: center;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
        }
        
        .logout a {
            text-decoration: none;
            background-color: transparent;
            padding: 5px 10px 5px 10px;
            color: #fff;
            font-weight: 700;
            font-size: 12px;
            transition: background-color 0.3s ease;
        }
        
        
        .logout a:hover {
            text-decoration: none;
            background-color: black;
            padding: 5px 10px 5px 10px;
            color: #d70c0c;
            transition: background-color 0.3s ease;
        }
        
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #f9f9f9;
            min-width: 150px;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            z-index: 1;
            text-align: center;
        }
        
        .dropdown-content a {
            color: black;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            font-size: 12px;
            text-align: left;
            font-weight: bold;
        }
        
        .dropdown-content a:hover {
            background-color: #d70c0c;
            color: white;
        }
        body {
            font-family: "Open Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", Helvetica, Arial, sans-serif; 
        }
        .row{
            margin-top: calc(5* var(--bs-gutter-y));
            --bs-gutter-x: 0;
            --bs-gutter-y: 0;
            display: flex;
            margin-right: calc(-0.5* var(--bs-gutter-x));
            margin-left: calc(0* var(--bs-gutter-x));
        }
    </style>