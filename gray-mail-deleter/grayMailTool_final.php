<?php
session_start();
// *** ERROR CHECKING ***
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL | E_STRICT);
error_reporting(E_ALL ^ E_NOTICE);

// ***** VARIABLES *****
if (!$_SESSION['batchPending']) {
    $batch_btn_style = 'class="btn btn-info" value="Start Batch"';
} else {
    $batch_btn_style = 'class="btn btn-success" value="Check Batch"';
}
$up_down_load_btn = 'class="btn btn-info" value="Upload"';
if ($_SESSION['activeSession']) {
    $abort_btn = 'style="display:inline;"';
} else {
    $abort_btn = 'style="display:none;"';
}
// ********************

// ***** DEBUGGER *****
// echo '<pre>';
// print_r($inipath);
// echo '</pre>';
// exit;
// ********************

//***************************
//*** FUNCTIONS
//***************************
// function startSession() {
//     session_start();
//     $_SESSION['activeSession'] = true;
//     $abort_btn = 'style="display:inline;"';
// }

function readCSV($csvFile){
    $file_handle = fopen($csvFile, 'r');
    while (!feof($file_handle)) {
        $line_of_text[] = fgetcsv($file_handle);
    }
    foreach ($line_of_text as $value){
        // $email_array[] = $value[0];
        $email = $value[0];
        if (strpos($email, '@') > 0) {
          $email_array[] = $email;
        }
    }
    fclose($file_handle);
    return $email_array;
}

function writeCSV($csvFile, $list){
    $file_handle = fopen($csvFile, 'w');
    foreach ($list as $line) {
        fputcsv($file_handle, explode(',',$line));
    }
    fclose($file_handle);
}

function closeSession() {
    //remove variables and destroy SessionHandler
    session_unset();
    session_destroy();

    //delete 'result_emails.csv'
    if (file_exists('result_emails.csv')) {
        unlink('result_emails.csv');
    }
}

function mailchimpCurlConnect($requestType, $data = array(), $url_end)
{
    $config = parse_ini_file('../config.ini', true);
    $apiKey = $config['apiKey'];
    $listId = '382de78e4c';
    $dataCenter = substr($apiKey, strpos($apiKey, '-') + 1);
    // IF batch operation
    if(preg_match('/\/batches/', $url_end)) {
        $url = 'https://' . $dataCenter . '.api.mailchimp.com/3.0' . $url_end;
    // IF single email passed
    } else {
        $memberId = md5(strtolower($data));
        $url = 'https://' . $dataCenter . '.api.mailchimp.com/3.0/lists/' . $listId . '/members/' . $memberId . $url_end;
    }

    $headers = array(
        'Content-Type: application/json',
        'Authorization: Basic '.base64_encode('user:'.$apiKey)
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERPWD, 'user:' . $apiKey);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestType);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    if ($requestType != 'GET') {
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $result = curl_exec($ch);

    if (!curl_errno($ch)) {
        switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
            case 200: //OK
                break;
            case 204: //No Content
                break;
            default:
                $errorCode = 'Unexpected HTTP code: ' . $http_code . "\n";
        }
    }
    curl_close($ch);

    // IF batch operation
    if(preg_match('/\/batches/', $url_end)) {
        $batchId = json_decode($result)->id;
        $response = array ($errorCode, $batchId, $result);
    // IF single email passed
    } else {
        $response = array ($errorCode, $result);
    }
    return $response;

    //From Terminal:
    //curl -H "Authorization: apikey xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-usx" https://us5.api.mailchimp.com/3.0/lists/382de78e4c | python -mjson.tool
}


function batch_process($requestType, $emails = array(), $path_end)
{
    $listId = '382de78e4c';
    $data = new stdClass();
    $data->operations = array();

    foreach ($emails as $email) {
        $memberId = md5(strtolower($email));
        $batch = new stdClass();
        $batch->method = $requestType;
        $batch->path = 'lists/' . $listId . '/members/' . $memberId . $path_end;
        $batch->body = json_encode(
            array(
                'email_address' => $email
            )
        );
        $batch->params = new stdClass();
        $batch->params->fields = 'email_address,status,timestamp_signup,timestamp_opt,member_rating';

        $data->operations[] = $batch;
    }

    return $data;
}

// ************************
// ***** START BATCH
// ************************
if (isset($_POST['batchSubmit'])) {
do { // do-while to break from starting batch if file uploaded is invalid
    // Show abort close button and set 'activeSession' session variable
    if (!$_SESSION['activeSession']) {
        $_SESSION['activeSession'] = true;
        $abort_btn = 'style="display:inline;"';
    }
    // *** Submit Batch Op ***
    if (!$_SESSION['batchPending']) {
        // echo '<pre>';
        // echo 'BATCH STATUS: request submitted';
        // echo '</pre>';
        // $_SESSION['batchPending'] = true;
        // exit;

        //*** EMPTY UPLOADS DIRECTORY ***
        $uploadFiles = glob('uploads/*');
        foreach($uploadFiles as $file){
            $lastModifiedTime = filemtime($file);
            $currentTime = time();
            $timeDiff = abs($currentTime - $lastModifiedTime)/(60*60); //in hours
            if (is_file($file) && $timeDiff > 2) { //check if file is modified before 2 hours
                unlink($file); //delete file
            }
        }

        // *** UPLOAD/READ FILE AND VALIDATION ***
        $email_addresses = array();
        $emailRegex = '/(E|e)mail\s(A|a)ddress/';
        $fileTemp = $_FILES['fileToUpload']['tmp_name'];
        $fileName = explode('.', $_FILES['fileToUpload']['name']);
        $ext = end($fileName);
        $filePath = 'uploads/' . basename($fileName[0] . '.' . $ext);

        //validate file to upload
        if(empty($fileTemp)) {
            $error = 'Please select a file to be uploaded.';
        } else {
            $allowed = array('txt', 'csv');

            if(in_array($ext, $allowed) === false) {
                $error = '<strong>'.$fileName[0].'</strong>\'s file extension ' . $ext . ' is not allowed.'.'<br>';
            }
        }

        // check if error
        if(empty($error)) {
            if(!file_exists('uploads')) {
                mkdir('uploads', 0777);
            }
            //upload file
            move_uploaded_file($fileTemp, $filePath);
            $email_addresses = readCSV($filePath);
            //check if first line finds "Email Address" label
            if(preg_match($emailRegex, $email_addresses[0])) {
                //if so, remove first line
                array_shift($email_addresses);
            }
        } else {
            $output = '<pre>'.$error.'</pre>';
            break;
        }

    // // *** Submit Batch Op ***
    // if (!$_SESSION['batchPending']) {
        // echo '<pre>';
        // echo 'BATCH STATUS: request submitted';
        // echo '</pre>';
        // $_SESSION['batchPending'] = true;
        // exit;
        // Assign batch operation
        $batch_data = batch_process('GET', $email_addresses, '');
        $res = mailchimpCurlConnect('POST', $batch_data, '/batches');
        $batchId = $res[1];
        $_SESSION['batchPending'] = true;
        $_SESSION['batchId'] = $batchId;
        $batch_btn_style = 'class="btn btn-success" value="Check Batch"';
        $output = '<pre>'.'BATCH STATUS: request submitted'.'</pre>';
    // *** Check Batch Op ***
    } else {
        // echo '<pre>';
        // echo 'BATCH STATUS: pending';
        // echo '</pre>';
        // exit;
        // Check on batch operations
        $res = mailchimpCurlConnect('GET', '', '/batches/' . $_SESSION['batchId']);
        // $batch_btn_style = 'class="btn btn-success" value="Check Batch"';
        $batch_check = json_decode($res[2]);
        $batch_status = $batch_check->status;
        $batch_tally = $batch_check->finished_operations . ' out of ' . $batch_check->total_operations . ' complete';

        if ($batch_status == 'finished') {
            $output_link = $batch_check->response_body_url;
            $output = '<pre>'.'<a href="' . $output_link . '">Click here to download output</a>'.'</pre>';
        } else {
            $output = '<pre>'.'BATCH STATUS of BATCH ID ' . $_SESSION['batchId'] . ': ' . $batch_status . '<br>' . $batch_tally.'</pre>';
        }
    }
} while(false); // do-while set to run once
}

// ************************
// ***** UPLOAD
// ************************
if (isset($_POST['JSONsubmit'])) {
    // Show abort close button and set 'activeSession' session variable
    if (!$_SESSION['activeSession']) {
        $_SESSION['activeSession'] = true;
        $abort_btn = 'style="display:inline;"';
    }
    // days_threshold from UI
    $days_threshold = $_POST['days'];
    // echo '<pre>';
    // echo 'Session Closed';
    // echo '</pre>';
    // closeSession();
    // exit;
    // Upload files to 'uploads/'
    if (!$_SESSION['hasUploaded']){
        $jsonFiles = array();
        $content = array();
        if (!empty(array_filter($_FILES['files']['name']))) {
            foreach($_FILES['files']['name'] as $i => $name) {

                $tmp = $_FILES['files']['tmp_name'][$i];

                // Parse .json files
                $json_content = file_get_contents($tmp);
                $json_content = json_decode($json_content);
                if (empty($json_content)) {
                    continue;
                }
                $status_code = $json_content[0]->status_code;
                if ($status_code == 200) {
                    $email_address = json_decode($json_content[0]->response)->email_address;
                    $subscription_status = json_decode($json_content[0]->response)->status;
                    $member_rating = json_decode($json_content[0]->response)->member_rating;
                    $timestamp_opt = json_decode($json_content[0]->response)->timestamp_opt;
                    // Calculate time since opt in
                    $diffTime = time() - strtotime($timestamp_opt);
                    $days = $days_threshold;

                    // Print emails under requirements
                    if ($member_rating <= 2 && $subscription_status == 'subscribed' && $diffTime > 60*60*24*$days) {
                        array_push($content, $email_address);
                    }
                    // Print emails with fields
                    // $user_data = $email_address . ',' . $subscription_status . ',' . $member_rating;
                    // array_push($content, $user_data);
                } else {
                    continue;
                }
            }
        writeCSV('result_emails.csv', $content);
        $_SESSION['hasUploaded'] = true;
        $up_down_load_btn = 'class="btn btn-success" value="Download"';
        $delete_btn = '<input class="btn btn-danger" type="submit" name="Delete" value="Delete">';
        } else {
            $output = '<pre>'.'Please select files to be uploaded.'.'</pre>';
        }

    } else {
        //*** DOWNLOAD ***
        if (file_exists('result_emails.csv')) {
            $downloadFilename = 'gray_mail_results_' . date('Y-m-d') . 'T' . date('H-i-sa') . '.csv';
            $_SESSION['hasDownloaded'] = true;
            // $output = '<pre>'.'Fake downloading ' . $downloadFilename . '...'.'</pre>';
            header("Content-Disposition: attachment; filename=\"".$downloadFilename."\"");
            header("Content-type: application/octet-stream");
            readfile('result_emails.csv');
            exit;
        }
    }
    // $delete_btn = '<input class="btn btn-danger" type="submit" name="Delete" value="Delete">';
    //closeSession();
}

// ************************
// ***** DELETE
// ************************
if (isset($_POST['Delete'])) {
    // echo '<pre>';
    // echo 'Session Closed';
    // echo '</pre>';
    // closeSession();
    // exit;
    if ($_SESSION['hasDownloaded']) {
        $output = '<pre>'.'<strong>Delete request sent...</strong>'.'<br>'.'</pre>';
        // Delete emails here
        //
    } else {
        $output = '<pre>'.'Please upload files and download CSV file before deleting list of emails.'.'<br>'.'</pre>';
        $_SESSION['hasUploaded'] = false;
    }
}

// ************************
// ***** CLOSE SESSION
// ************************
if (isset($_POST['Abort'])) {
  closeSession();
  $abort_btn = 'style="display:none;"';
  $batch_btn_style = 'class="btn btn-info" value="Start Batch"'; //need these? doesn't go to top of script after executing
  // $up_down_load_btn = 'class="btn btn-info" value="Upload"'; //need these? why does this one work?
}
?>

<!--*************************
// *** FORM
//************************-->
<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title>Gray Mail Tool</title>
        <link rel="stylesheet" type="text/css" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
        <link rel="stylesheet" type="text/css" href="style.css">
    </head>
  <body>

    <div class="container">
        <div class="row">
            <div class="col-xs-12 text-center">
                <h1>Gray Mail Tool</h1>
            </div>
            <div class="wrapper">
                <div class="col-xs-12">
                    <h4>Step 1: Upload Mailchimp export of emails to process (.csv file)</h4>
                    <h4>Step 2: Start batch operation</h4>
                  <!-- </div> -->
                  <!-- <div class="col-xs-12"> -->
                    <form method="post" enctype="multipart/form-data" class="form-inline">
                        <div class="form-group">
                            <input type="file" class="form-control-file" id="file" name="fileToUpload">
                        </div>
                        <button <?php echo $abort_btn ?> type="submit" name="Abort" class="close" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <input <?php echo $batch_btn_style ?> type="submit" name="batchSubmit">
                    </form>
                </div>
                <div class="col-xs-12">
                    <h4>Step 3: Upload unzipped downloaded files from batch operation (.tar.gz file)</h4>
                    <h4>Step 4: Select number of days to account for new subscribers</h4>
                    <h4>Step 5: Download and Delete</h4>
                <!-- </div>
                <div class="col-xs-12"> -->
                    <form method="post" enctype="multipart/form-data" class="form-inline">
                        <div class="form-group">
                            <input type="file" class="form-control-file" id="file" name="files[]" multiple>
                        </div>
                        <label for="numDays">Days: </label>
                        <input id="numDays" type="number" name="days" min="1" max="28" step="1" required>
                        <input <?php echo $up_down_load_btn ?> type="submit" name="JSONsubmit" >
                        <?php echo $delete_btn ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-xs-12" id="output">
                <?php
                    // echo '<pre>';
                    echo $output;
                    // echo '</pre>';
                ?>
            </div>
        </div>
    </div>

  </body>
</html>
