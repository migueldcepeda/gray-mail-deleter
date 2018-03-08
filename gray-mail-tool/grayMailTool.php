<?php
session_start();
// **** CONFIG ****
// phpinfo();
// $inipath = php_ini_loaded_file();
// echo '<pre>';
// print_r($inipath);
// echo '</pre>';
// exit;

// **** ERROR CHECKING ****
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL | E_STRICT);
// error_reporting(E_ALL ^ E_NOTICE);

// **** VARIABLES ****
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

// **** DEBUGGER ****
// echo '<pre>';
// $inipath = php_ini_loaded_file();
// print_r($inipath);
// echo '</pre>';
// exit;
// ********************

// Get Campaign Folders
$campaign_folders = createFolderOptions();

// Get Campaigns
if (isset($_POST['folderID']) && $_POST['folderName']) {
    $recip_count_max = json_encode(retrieveCampaigns(25)[0]);
    $campaign_data = json_encode(retrieveCampaigns(25)[1]);
    $_SESSION['recipCount'] = $recip_count_max;
    $_SESSION['campaignFolderName'] = $_POST['folderName'];
    echo $campaign_data;
    exit;

}

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
    if (file_exists('result_emails.csv')) {
        unlink('result_emails.csv');
    }
}

//**** Download Function (Not Used) ****
function download($url) {
    set_time_limit(0);

    $file_base = basename($url);
    $targzUrlRegex = '/.+.tar.gz/';
    preg_match($targzUrlRegex, $file_base, $matches);

    $file = 'uploads/' . $matches[0];

    $fp = fopen($file, 'w');

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);

    $data = curl_exec($ch);

    curl_close($ch);
    fclose($fp);

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename='.basename($file));
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file));
    ob_clean();
    flush();
    readfile($file);
}
//**** END Download Function ****

function mailchimpCurlConnect($requestType, $data = array(), $listURL, $url_end) {
    $config = parse_ini_file('../config.ini', true);
    $apiKey = $config['apiKey'];
    $listId = '382de78e4c';
    $dataCenter = substr($apiKey, strpos($apiKey, '-') + 1);

    if ($requestType == 'GET') {
        $url_end .= '?' . http_build_query($data);
    }
    if ($listURL) {
        $url = 'https://' . $dataCenter . '.api.mailchimp.com/3.0' . '/lists/' . $listId . $url_end;
    } else {
        $url = 'https://' . $dataCenter . '.api.mailchimp.com/3.0' . $url_end;
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
    // Otherwise
    } else {
        $response = array ($errorCode, $result);
    }
    return $response;
}

function retrieveFolders() {
    $fields = array(
        'count' => '50'
    );
    $MC_groups = mailchimpCurlConnect('GET', $fields, FALSE, '/campaign-folders');
    $MC_folders = json_decode($MC_groups[1])->folders;

    // Create array of folder data
    $folder_data = array();

    foreach($MC_folders as $i => $f) {
        $data = new stdClass();
        $data->name = $f->name;
        $data->id = $f->id;

        array_push($folder_data, $data);
    }
    return $folder_data;
}

function createFolderOptions() {
    $folders = retrieveFolders();
    $htmlOptions = '<option value="" disabled selected>-- Select --</option>';
    foreach($folders as $f) {
        $htmlOptions .=
            '<option value="'
            . $f->id
            . '">'
            . $f->name
            . '</option>';
    }
    return $htmlOptions;
}

function retrieveCampaigns($camp_count) {
    $folderID = $_POST['folderID'];
    // $camp_count = 25;

    $campaign_data = array();
    $fields = array(
        'folder_id' => $folderID,
        'sort_field' => 'send_time',
        'sort_dir' => 'DESC',
        'count' => $camp_count
    );

    $MC_group = mailchimpCurlConnect('GET', $fields, FALSE, '/campaigns');
    $MC_group_campaigns = json_decode($MC_group[1])->campaigns;

    for ($i = 0; $i < sizeof($MC_group_campaigns); $i++ ) {
        $camp = $MC_group_campaigns[$i];
        $campaignRecip[] = $camp->emails_sent;
        $campaignID = $camp->id;
        $campaignTitle = $camp->settings->title;
        $campaignTime = $camp->send_time;
        $campaign_info[$campaignID] = $campaignTitle . ' - ' . $campaignTime;
    }
    $recip_count_max = max($campaignRecip);
    $campaign_data = array($recip_count_max, $campaign_info);

    return $campaign_data;
}

function batch_process($requestType, $batchParams = array(), $batchPaths = array()) {
    $listId = '382de78e4c';
    $data = new stdClass();
    $data->operations = array();

    foreach($batchPaths as $path) {
        foreach ($batchParams as $paramObj) {
            $batch = new stdClass();
            $batch->method = $requestType;
            $batch->path = $path;
            $batch->params = new stdClass();
            $batch->params = array(
                'offset' => $paramObj->offset,
                'count' => 1000
            );

            $data->operations[] = $batch;
        }
    }
    return $data;
}

// ************************
// ***** START BATCH
// ************************
if (isset($_POST['batchSubmit'])) {
    // Show abort close button and set 'activeSession' session variable
    if (!$_SESSION['activeSession']) {
        $_SESSION['activeSession'] = true;
        $abort_btn = 'style="display:inline;"';
    }

    /*** Data Persistance ***/
    // Use $_SESSION['campaignFolderName'] to pass in selected attribute to $campaign_folders option tags
    // echo '<pre>';
    // print_r($_SESSION['campaignFolderName']);
    // echo '<br>';
    // print_r($campaign_folders);
    // echo '</pre>';
    // exit;

    // *** Submit Batch Op ***
    if (!$_SESSION['batchPending'] && isset($_POST['campaignData'])) {

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

        //*** BATCH OPERATION PREPROCESSING ***

        // Create batch params array
        $offset = array();
        for ($i = 0; $i < $_SESSION['recipCount']; $i += 1000 ) {
            $batch_params[]->offset = $i;
        }

        // Create batch path array: urls for different campaigns
        foreach($_POST['campaignData'] as $id) {
            $batch_path[] = '/reports/' . $id . '/email-activity';
        }

        // Create batch operation
        $batch_data = batch_process('GET', $batch_params, $batch_path);

        // Assign batch operation
        $batch_res = mailchimpCurlConnect('POST', $batch_data, FALSE, '/batches');
        $batchId = $batch_res[1];
        $_SESSION['batchPending'] = true;
        $_SESSION['batchId'] = $batchId;
        $batch_btn_style = 'class="btn btn-success" value="Check Batch"';
        $output = '<pre>'.'CAMPAIGN TYPE: '.$_SESSION['campaignFolderName'].'<br>'.'BATCH STATUS: request submitted'.'</pre>';

    // *** Check Batch Op ***
    } else if ($_SESSION['batchPending'] && !isset($_POST['campaignData']))  {
        $batch_res = mailchimpCurlConnect('GET', '', FALSE, '/batches/' . $_SESSION['batchId']);
        $batch_check = json_decode($batch_res[2]);
        $batch_status = $batch_check->status;
        $batch_tally = $batch_check->finished_operations . ' out of ' . $batch_check->total_operations . ' complete';

        if ($batch_status == 'finished') {
            $output_link = $batch_check->response_body_url;

            // download file to uploads directory
            // **** Download File ****
            // download($output_link);
            // **** END Download File ****

            // decompress from gz
            // $p = new PharData('files.tar.gz');
            // $p->decompress(); // creates files.tar

            // unarchive from the tar
            // $phar = new PharData('files.tar');
            // $phar->extractTo('new_dir');

            $output = '<pre>'.'<a href="' . $output_link . '">Click here to download output</a>'.'</pre>';
        } else {
            $output = '<pre>'.'CAMPAIGN TYPE: '.
            $_SESSION['campaignFolderName'].'<br>'.
            'BATCH STATUS of BATCH ID ' . $_SESSION['batchId'] . ': ' . $batch_status . '<br>' . $batch_tally.'</pre>';
        }
    } else {
        $output = '<pre>'.'Please select campaigns.'.'</pre>';
    }
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
    // Upload files to 'uploads/'
    if (!$_SESSION['hasUploaded']){
        $jsonFiles = array();
        $unopened_emails = array();
        $email_count = array();
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

                    $emails_data = json_decode($json_content[0]->response)->emails;
                    $no_email_activity = array_filter(
                        $emails_data,
                        function($e) {
                            return empty($e->activity);
                        }
                    );


                    $email_addresses = array_map(
                        function($e) {
                            return $e->email_address;
                        },
                        $no_email_activity
                    );

                    array_push($unopened_emails, ...$email_addresses);
                } else {
                    continue;
                }
            }

            foreach($unopened_emails as $i => $e) {
                if (strpos($e, '@') > 0) {
                    $email_count[trim($e)]++;
                }
            }

            foreach ($email_count as $e => $count) {
                if ($count >= 10) {
                    array_push($content, $e);
                }
            }

            writeCSV('result_emails.csv', $content);
            $_SESSION['hasUploaded'] = true;
            $up_down_load_btn = 'class="btn btn-success" value="Download"';
        } else {
            $output = '<pre>'.'Please select files to be uploaded.'.'</pre>';
        }

    } else {
        //*** DOWNLOAD ***
        if (file_exists('result_emails.csv')) {
            $downloadFilename = 'gray_mail_results_' . $_SESSION['campaignFolderName'] . '_' . date('Y-m-d') . 'T' . date('H-i-sa') . '.csv';
            // $_SESSION['hasDownloaded'] = true;
            $_SESSION['hasUploaded'] = false;
            header("Content-Disposition: attachment; filename=\"".$downloadFilename."\"");
            header("Content-type: application/octet-stream");
            readfile('result_emails.csv');
            exit;
        }
    }
}

// ************************
// ***** CLOSE SESSION
// ************************
if (isset($_POST['Abort'])) {
  closeSession();
  $abort_btn = 'style="display:none;"';
  $batch_btn_style = 'class="btn btn-info" value="Start Batch"';
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
        <script type="text/javascript">
            $(document).ready(function(){
                var update = false;
                $('select[name="campaignType"]').on('change', function(){
                    var folder_id = $('select[name="campaignType"]').val();
                    var folder_name = $('select[name="campaignType"] option:selected').text();
                    $.ajax({
                        type: 'post',
                        data: {folderID: folder_id, folderName: folder_name},
                        success: function(response) {
                            console.log(response);
                            var camp_data = jQuery.parseJSON(response);
                            var htmlOptions = $.map(camp_data, function(val, key) {
                                var option =
                                    '<option value="'
                                    + key
                                    + '">'
                                    + val
                                    + '</option>';
                                return option;
                            }).join('');

                            if (update) {
                                $('#campaigns').html(htmlOptions);
                            } else {
                                var $newSelect =
                                `<div class="form-group">
                                <select multiple class="form-control" name="campaignData[]" id="campaigns">`
                                + htmlOptions +
                                `</select>
                                </div>`;
                                $('select[name="campaignType"]').after($newSelect);
                            }
                            update = true;
                            // $('select[name="campaignData"]').append(htmlOptions);
                        }
                    });
                });
            });
        </script>
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
                        <h4>Step 1: Select campaign folder/type</h4>
                        <h4>Step 2: Select previous campaigns to track gray mail</h4>
                        <h4>Step 3: Start batch operation</h4>
                        <form method="post" enctype="multipart/form-data" class="form-inline">
                            <div class="form-group">
                                <select class="form-control" name="campaignType" >
                                    <?php echo $campaign_folders ?>
                                </select>
                            </div>
                            <button <?php echo $abort_btn ?> type="submit" name="Abort" class="close" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            <input <?php echo $batch_btn_style ?> type="submit" name="batchSubmit">
                        </form>
                    </div>
                    <div class="col-xs-12">
                        <h4>Step 4: Upload unzipped downloaded files from batch operation (.tar.gz file)</h4>
                        <h4>Step 5: Download .csv of gray mail addresses</h4>
                        <form method="post" enctype="multipart/form-data" class="form-inline">
                            <div class="form-group">
                                <input type="file" class="form-control-file" id="file" name="files[]" multiple>
                            </div>
                            <input <?php echo $up_down_load_btn ?> type="submit" name="JSONsubmit" >
                        </form>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xs-12" id="output">
                    <?php
                        echo $output;
                    ?>
                </div>
            </div>

        </div>
    </body>
</html>

<!-- TODO -->
<!-- folder name and campaign files persistance -->
