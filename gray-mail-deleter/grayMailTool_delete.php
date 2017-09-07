<?php
session_start();
$listId = '382de78e4c';
// *** ERROR CHECKING ***
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL | E_STRICT);
// error_reporting(E_ALL ^ E_NOTICE);

//***************************
//*** FUNCTIONS
//***************************
function readCSV($csvFile){
    $file_handle = fopen($csvFile, 'r');
    while (!feof($file_handle)) {
        $line_of_text[] = fgetcsv($file_handle);
    }
    foreach ($line_of_text as $value){
        $email_array[] = $value[0];
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

    //delete 'report.csv'
    unlink('report.csv');
}

function deleteMailchimpMember($email,$listId)
{
    $apiKey = 'e94add562694872e8855b1efa1a04029-us5';
    $memberId = md5(strtolower($email));
    $dataCenter = substr($apiKey, strpos($apiKey, '-') + 1);
    $url = 'https://' . $dataCenter . '.api.mailchimp.com/3.0/lists/' . $listId . '/members/' . $memberId;
    $json = json_encode
    (
        [
            'email_address' => $email
        ]
    );
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_USERPWD, 'user:' . $apiKey);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    $result = curl_exec($ch);

    if (!curl_errno($ch)) {
      switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
        case 200: //OK
          break;
        case 204: //No Content
          break;
        default:
          //echo 'Unexpected HTTP code: ', $http_code, "\n";
          $errorCode = 'Unexpected HTTP code: ' . $http_code . "\n";
      }
    }
    //$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    //return $httpCode;
    return $errorCode;
}

//***************************
//*** UPLOAD
//***************************
if (isset($_POST['Upload'])) {

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


// *** VARIABLES AND ARRAYS ***
// Set count variable
$min_open_count = $_POST['counter'];

$csvFiles = array();
$output = array();

// Check if count value was input
// if(!empty($min_open_count) && $min_open_count >= 0) {

//*** FILE UPLOAD AND VALIDATION ***
if (!empty($_FILES)) {
    foreach($_FILES['files']['name'] as $i => $name) {
        $name = $_FILES['files']['name'][$i];
        $size = $_FILES['files']['size'][$i];
        $type = $_FILES['files']['type'][$i];
        $tmp = $_FILES['files']['tmp_name'][$i];

        $explode = explode('.', $name);
        $ext = end($explode);

        $path = 'uploads/';
        $path = $path . basename( $explode[0] . '.' . $ext );
        array_push($csvFiles, $path);

        $errors = array();

        // echo '<pre>';
        // print_r($ext);
        // echo '</pre>';

        //if(empty($_FILES['files']['tmp_name'][$i])) {
        if(empty($tmp)) {
            $errors[] = 'Please choose at least 1 file to be uploaded.';
        } else {
            $allowed = array('txt', 'csv');
            $max_size = 10000000;

            if(in_array($ext, $allowed) === false) {
                $errors[] = '<strong>'.$name.'</strong>\'s file extension ' . $ext . ' is not allowed.'.'<br>';
            }

            if($size > $max_size) {
                $errors[] = 'The file ' . $name . ' size is too large.'.'<br>';
            }
        }

        if(empty($errors)) {
            if(!file_exists('uploads')) {
                mkdir('uploads', 0777);
            }
            if(move_uploaded_file($tmp, $path)) {
                $output[] = 'The file <strong>' . $name . '</strong> successfully uploaded.'.'<br>';
            } else {
                $output[] = 'Something went wrong while uploading the file <strong>' . $name . '</strong>'.'<br>';
            }
        } else {
            $output = $errors;
            // echo '<pre>';
            // print_r($errors);
            // echo '<pre>';
        }
    }
}


//*** PARSE FILES ***
// 1. Create Super Array
$email_array = array();
$emailRegex = '/(E|e)mail\s(A|a)ddress/';

foreach ($csvFiles as $csvFile){
    //read files
    $lines = readCSV($csvFile);
    //check if first line finds "Email Address" label
    if(preg_match($emailRegex, $lines[0])) {
        //if so, remove first line
        array_shift($lines);
    }
    //push to array
    array_push($email_array, ...$lines);
}

$email_count = array();

// 2. Parse Array For Emails
foreach ($email_array as $i => $e) {
    if (strpos($e, '@') > 0) {
      $email_count[trim($e)]++;
    }
}

// 3. Check Email Count
$content = array();
//$content = array();

foreach ($email_count as $e => $count) {

    if ($count >= $min_open_count) {
        array_push($content, $e);
    }
}

//*******************
// Set csv to write to
writeCSV('report.csv', $content);
//*******************
//$resultCSV = 'report.csv';
$_SESSION['data'] = $content;
//$_SESSION['message'] = $output;
}

//***************************
//*** DOWNLOAD
//***************************
if (isset($_POST['Download']) && file_exists('report.csv')) {
    //set download session variable
    $_SESSION['hasDownloaded'] = true;
    //download 'report.csv' as 'download.csv'
    header("Content-Disposition: attachment; filename=\""."download.csv"."\"");
    header("Content-type: application/octet-stream");
    readfile('report.csv');
    exit;
}

//***************************
//*** DELETE
//***************************
if (isset($_POST['Delete'])) {
  //$output = array();
    if ($_SESSION['hasDownloaded']) {
        $deleteCount = 0;
        $output[] = '<strong>Deleting data...</strong>'.'<br>';

        foreach ($_SESSION['data'] as $email) {
          //*******************
          // Deletes emails
          $error = deleteMailchimpMember($email, $listId).'<br>';
          //*******************
          if (empty($error)) {
            $deleteCount++;
          } else {
            $errors[] = 'ERROR deleting <strong>' .$email. '</strong> - ' . $error;
          }
        }
        $output[] = '<strong>' . $deleteCount . '</strong> email addresses were successfully deleted.'.'<br>';
        if (!empty($errors)) {
          //$output[] = $errors;
          array_push($output, ...$errors);
        }
    } else {
        $output = 'Please upload files and download CSV file before deleting list of emails.'.'<br>';
    }

    closeSession();
}
?>

<!--*************************
// *** FORM
//************************-->
<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Gray Mail Deleter</title>
    <link rel="stylesheet" type="text/css" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <link rel="stylesheet" type="text/css" href="style.css">
  </head>
  <body>

    <div class="container">
      <div class="row">
        <div class="col-xs-12 text-center">
          <h1>Gray Mail Deleter</h1>
            <form method="post" enctype="multipart/form-data" class="form-inline">
              <input type="file" name="files[]" multiple>
              <input type="number" name="counter" placeholder="Count" min="1">
              <input class="btn btn-info" type="submit" name="Upload" value="Upload">
              <input class="btn btn-success" type="submit" name="Download" value="Download">
              <input class="btn btn-danger" type="submit" name="Delete" value="Delete">
              <!-- <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#myModal">Delete</button> -->
            </form>
        </div>
      </div>

      <div class="row">
        <div class="col-xs-12" id="output">
          <?php
            if (is_array($output)) {
              foreach($output as $value) {
                echo $value;
              }
            } else {
              echo $output;
            }
          ?>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <!-- <div class="modal fade" id="myModal" role="dialog">
      <div class="modal-dialog"> -->

        <!-- Modal content-->
        <!-- <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title">Delete Subscribers</h4>
          </div>
          <div class="modal-body">
            <p>Deleting subscribers cannot be undone. Are you sure you'd like to proceed?</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
            <input class="btn btn-danger" type="submit" name="Delete" value="Delete">
          </div>
        </div> -->

      <!-- </div>
    </div> -->

  </body>
</html>
