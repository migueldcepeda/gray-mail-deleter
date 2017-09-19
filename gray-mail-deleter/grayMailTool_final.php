<?php
session_start();
// $listId = '382de78e4c';
// *** ERROR CHECKING ***
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL | E_STRICT);
error_reporting(E_ALL ^ E_NOTICE);

// ***** VARIABLES *****
$batch_btn_style = 'class="btn btn-info" value="Start Batch"';
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
    unlink('result_emails.csv');
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
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestType);//'DELETE');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    if ($requestType != 'GET') {
        curl_setopt($ch, CURLOPT_POST, TRUE); //added
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
// ***** BATCH SUBMIT
// ************************
if (isset($_POST['batchSubmit'])) {

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

    // *** UPLOAD/READ FILE ***
    $email_addresses = array();
    $emailRegex = '/(E|e)mail\s(A|a)ddress/';
    $fileTemp = $_FILES['fileToUpload']['tmp_name'];
    $fileName = explode('.', $_FILES['fileToUpload']['name']);
    $ext = end($fileName);
    $filePath = 'uploads/' . basename($fileName[0] . '.' . $ext);

    move_uploaded_file($fileTemp, $filePath);
    $email_addresses = readCSV($filePath);
    //check if first line finds "Email Address" label
    if(preg_match($emailRegex, $email_addresses[0])) {
        //if so, remove first line
        array_shift($email_addresses);
    }

    if (!$_SESSION['batchPending']) {
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
        $output = 'BATCH STATUS: request submitted';
    } else {
        // echo '<pre>';
        // echo 'BATCH STATUS: pending';
        // echo '</pre>';
        // exit;
        // Check on batch operations
        $res = mailchimpCurlConnect('GET', '', '/batches/' . $_SESSION['batchId']);
        $batch_btn_style = 'class="btn btn-success" value="Check Batch"';
        $batch_check = json_decode($res[2]);
        $batch_status = $batch_check->status;
        $batch_tally = $batch_check->total_operations . ' out of ' . $batch_check->finished_operations . ' complete';
        if ($batch_status == 'finished') {
            $output_link = $batch_check->response_body_url;
            $output = '<a href="' . $output_link . '">Click here to download output</a>';
        } else {
            $output = 'BATCH STATUS of ' . $_SESSION['batchId'] . ': ' . $batch_status . '<br>' . $batch_tally;
        }
    }
}

// ************************
// ***** UPLOAD
// ************************
if (isset($_POST['JSONsubmit'])) {
    // echo '<pre>';
    // echo 'Session Closed';
    // echo '</pre>';
    // closeSession();
    // exit;
    // Upload files to 'uploads/'
    $jsonFiles = array();
    $content = array();
    if (!empty($_FILES)) {
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
                $days = 14;

                // Print emails under requirements
                if ($member_rating <= 1 && $subscription_status == 'subscribed' && ($diffTime > 60*60*24*$days)) {
                    array_push($content, $email_address);
                }
                // Print emails with fields
                // $user_data = $email_address . ',' . $subscription_status . ',' . $member_rating;
                // array_push($content, $user_data);
            } else {
                continue;
            }
        }
    }

    writeCSV('result_emails.csv', $content);

    // *** DOWNLOAD ***
    if (file_exists('result_emails.csv')) {
        $downloadFilename = 'gray_mail_results_' . date('Y-m-d') . 'T' . date('H-i-sa') . '.csv';
        $delete_btn = '<input class="btn btn-danger" type="submit" name="Delete" value="Delete">';
        $_SESSION['hasDownloaded'] = true;
        header("Content-Disposition: attachment; filename=\"".$downloadFilename."\"");
        header("Content-type: application/octet-stream");
        readfile('result_emails.csv');
        exit;
    }
    //closeSession();
}

// ************************
// ***** DELETE
// ************************
if (isset($_POST['Delete'])) {
    if ($_SESSION['hasDownloaded']) {
        $output[] = '<strong>Delete request sent...</strong>'.'<br>';
        //*Delete emails here
    } else {
        $output = 'Please upload files and download CSV file before deleting list of emails.'.'<br>';
    }
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
            <!-- UI -->
            <div class="row">
                <div class="col-xs-12 text-center">
                    <h1>Gray Mail Tool</h1>
                </div>
                <div class="wrapper">
                    <div class="col-xs-12">
                        <h4>Step 1: Upload Mailchimp export of emails (.csv file)</h4>
                    </div>
                  <div class="col-xs-12">
                      <form method="post" enctype="multipart/form-data" class="form-inline" style="margin-top:25px;">
                          <div class="form-group">
                              <input type="file" class="form-control-file" id="file" name="fileToUpload">
                          </div>
                          <input <?php echo $batch_btn_style ?> type="submit" name="batchSubmit">
                      </form>
                  </div>
                  <div class="col-xs-12">
                      <h4>Step 2: Upload unzipped downloaded files from batch operation (.tar.gz file) </h4>
                  </div>
                  <div class="col-xs-12">
                      <form method="post" enctype="multipart/form-data" class="form-inline" style="margin-top:25px;">
                          <div class="form-group">
                              <input type="file" class="form-control-file" id="file" name="files[]" multiple>
                          </div>
                          <input class="btn btn-info" type="submit" name="JSONsubmit" value="Upload">
                          <?php echo $delete_btn ?>
                          <!-- <input class="btn btn-danger" type="submit" name="Delete" value="Delete"> -->
                      </form>
                  </div>
                </div>
            </div>
            <!-- /UI -->

            <!-- OUTPUT -->
            <div class="row">
              <div class="col-xs-12" id="output">
                <?php
                  echo $output;
                ?>
              </div>
            </div>
            <!-- /OUTPUT -->
        </div>

    </body>
</html>
