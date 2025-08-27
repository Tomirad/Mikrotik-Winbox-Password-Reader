<?php
namespace Treto\WinboxReader;

class WinboxReader
{
    private $stringCode;
    private $printText = '';

    public function __construct()
    {
        $this->stringCode = base64_decode($_SESSION['code'] ?? '[]');
    }

    private function strpad($string, $pad = 2, $strpad = ' ', $direction = STR_PAD_LEFT): string
    {
        return str_pad($string, $pad, $strpad, $direction);
    }

    private function header(): string
    {
        $print = '<pre>';
        $print .= "<h1>MikroTik Winbox Passwords Reader</h1>";
        $print .= "<style>button, input[type='file']::file-selector-button{
                padding: 2px 30px;
                border: 1px solid #ccc;
            }</style>";
        return $print;
    }

    private function getJsonFile()
    {
        $json = $this->stringCode;
        header("Content-Type: application/json");
        header("Content-Length: " . strlen($json));
        header("Content-Disposition: attachment; filename=addresses.json");
        header("Expires: 0");
        header("Cache-Control: must-revalidate");
        header("Pragma: public");

        $this->printText = $json;
    }

    private function getCsvFile()
    {
        $records = json_decode($this->stringCode, true);
        $csv     = fopen('php://memory', 'w');

        $i = 0;
        foreach ($records as $k => $record) {
            if ($i == 0) {
                $i++;
                $header = [];
                foreach ($record as $keyname => $value) {
                    $header[] = $keyname;
                }
                fputcsv($csv, $header, "\t");
            }
            fputcsv($csv, $record, "\t");
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        header("Content-Type: application/csv");
        header("Content-Length: " . strlen($content));
        header("Content-Disposition: attachment; filename=addresses.csv");
        header("Expires: 0");
        header("Cache-Control: must-revalidate");
        header("Pragma: public");

        $this->printText = $content;
    }

    public function index()
    {
        if (filter_has_var(INPUT_GET, 'json')) {
            $this->getJsonFile();
        } elseif (filter_has_var(INPUT_GET, 'csv')) {
            $this->getCsvFile();
        } elseif (filter_has_var(INPUT_POST, 'read')) {
            $this->readFile();
        } elseif (filter_has_var(INPUT_GET, 'clear')) {
            $this->destroySession();
        } else {
            $this->mainPage();
        }
        return $this->printText;
    }

    private function destroySession()
    {
        if (isset($_SESSION)) {
            session_destroy();
        }
        header("Location: " . $_SERVER['SCRIPT_NAME']);
    }

    private function getFile($fileName)
    {
        if (! file_exists($fileName)) {
            $this->printText = "-- FILE NOT EXITS --";
            exit;
        }
        $data = file_get_contents($fileName);
        return explode("\x00\x4d\x32\x07", $data);
    }

    private function convertDataString($textGroup)
    {
        $dataString = [];
        foreach ($textGroup as $g => $group) {
            if ($g == 0) {
                continue;
            }
            $recordString = '';
            $old          = '00';
            $continue     = true;
            $stringArray  = str_split($group);
            $i            = 0;
            foreach ($stringArray as $s => $chr) {
                $hex  = bin2hex($chr);
                $dec  = hexdec($hex);
                $chr  = str_replace(["\x0a", "\x0d"], "\xef", $chr);
                $hex  = str_replace(["0a", "0d"], "ef", $hex);
                $pair = $old . ' ' . $hex;
                $old  = $hex;

                if ($pair == '00 21') {
                    $continue = false;
                }

                if ($continue) {
                    continue;
                } else {
                    $i++;
                }

                if ($i <= 2) {
                    continue;
                }

                if ($hex == '00') {
                    $chr = ' ';
                }

                $recordString .= $chr;
            }
            $recordString = preg_replace('/[^\x20-\x7E]/', '', $recordString);
            $dataString[] = explode(' !', $recordString);
        }
        return $dataString;
    }

    private function createAddressList($dataString): array
    {
        $addressList = [];
        foreach ($dataString as $item) {
            $item                = array_map('trim', $item);
            $record              = new \stdClass;
            $record->address     = $item[0];
            $record->username    = $item[1];
            $record->password    = $item[6];
            $record->description = $item[5] ?? '';
            $record->group       = $item[3];
            $record->workspace   = $item[2];
            $record->romonagent  = $item[4];
            $addressList[]       = $record;
        }
        return $addressList;
    }

    private function readFile()
    {
        $this->printText = $this->header();
        $this->printText .= "<h2>Reading file {$_FILES['addressbook']['name']}</h2>";

        $fileName = $_FILES['addressbook']['tmp_name'] ?? 'Addresses.cdb';

        $data        = $this->getFile($fileName);
        $data        = $this->convertDataString($data);
        $addressList = $this->createAddressList($data);

        $_SESSION['code'] = base64_encode(json_encode($addressList, JSON_PRETTY_PRINT));

        $this->printText .= "\n Download List: <a href='?json'>addresses.json</a> | <a href='?csv'>addresses.csv</a> ";
        $this->printText .= "\n <a href='?clear'>Clear data</a> & back to main page";
    }

    private function mainPage()
    {
        $this->printText = $this->header();
        $this->printText .= "<form method='post' enctype='multipart/form-data'>
            <label for='addressbook'>Choose file to upload</label> <input type='file' for='addressbook' name='addressbook'
              accept='.dat, .cdb'> <button type='submit' name='read'>Read</button>
            </form>";
    }

}
