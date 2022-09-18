<?php

namespace Yarm\Tei\Http\Controllers;

use App\Models\File;
use App\Models\Ref;
use App\Models\Style;
use Illuminate\Support\Facades\Storage;

class TeiController extends Controller
{
    public static function unzipTei($data, $Files, $fileToBookshelfYes, $fileToBookshelfNo)
    {
        $fileData = pathinfo($data['name']);

        if (!storage::exists('DLBTUploads/unzipped/' . $fileData['filename'] . '/tei.xml')) {
            $zip = new \ZipArchive;
            $res = $zip->open(storage_path() . '/app/DLBTUploads/' . $data['name']);
            if ($res === true)
                $zip->extractTo(storage_path() . '/app/DLBTUploads/unzipped/' . $fileData['filename'] . '/');
            $zip->close();
            //check if zip contains xml!
            if (storage::exists('DLBTUploads/unzipped/' . $fileData['filename'] . '/tei.xml')) {
                $Files .= $fileToBookshelfYes;
            } else {
                storage::deleteDirectory('DLBTUploads/unzipped/' . $fileData['filename']);
                $Files .= $fileToBookshelfNo;
            }
        } else {
            $Files .= $fileToBookshelfYes;
        }
        return $Files;
    }

    public static function convertXml2HtmlOrEpub($request, $fileToConvert)
    {
        $htmlBody = '';
        $htmlFile = self::convertXMLToHTML($fileToConvert);
        if ($request['convFormat'] != 'epub') {
            if ($htmlFile !== 'Wrong Format' && $htmlFile !== 'File not found') {
                //cutBodyText from htmlFile
                $startBodyTag = strpos($htmlFile, '<body');
                $startTEIBack = strpos($htmlFile, '<!--TEI back-->');
                $htmlBody = substr($htmlFile, $startBodyTag, $startTEIBack - $startBodyTag) . '</body>';
            }
        }
        return [$htmlBody, $htmlFile];
    }

    public static function getHtmlFromZip($request, $fileToConvert)
    {

        try {
            $name = pathinfo($fileToConvert, PATHINFO_FILENAME);
            $fileToConvert = 'DLBTUploads/unzipped/' . $name . '/' . 'tei.xml';
            if (storage::exists($fileToConvert)) {
                $fileName = 'unzipped/' . $name . '/' . 'tei.xml';
                try {
                    $htmlBody = ExportController::createHtmlBodyFromZippedFile($fileName, $name);
                } catch (\Throwable $e) {
                    return redirect()->back()->withInput()
                        ->with('alert-danger', 'Error: Conversion failed! (No TEI?)');
                }
                if ($request['convFormat'] == 'epub') {
                    try {
                        $htmlFile = ExportController::createHtmlFile4Epub($htmlBody);
                    } catch (\Throwable $e) {
                        return redirect()->back()->withInput()
                            ->with('alert-danger', 'Error: Conversion failed! (Invalid HTML)');
                    }
                }
            } else {
                return redirect()->back()->withInput()
                    ->with('alert-danger', 'Error: File not found!');
            }
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()
                ->with('alert-danger', 'Error: Conversion failed!');
        }
        return [$htmlFile, $htmlBody];
    }

    public static function setTeiFlag($file, $fileName, $fileId)
    {
        $teiFlag = false;

        //Change TEI-Header if tei.xml
        $extension = $file->getClientOriginalExtension();

        if ($extension == 'xml' or $extension == 'zip') {
            try {
                $teiFlag = self::addNewTeiHeader($extension, $file, $fileName, $fileId);
            } catch (\Throwable $e) {
                return back()->with('alert-danger', 'Error Files 2: ' . $e->getMessage());
            }

        }
        return $teiFlag;

    }

    /**
     * @param $extension
     * @param $file
     * @param $fileName
     * @param $fileId
     * @return bool
     */
    public static function addNewTeiHeader($extension, $file, $fileName, $fileId)
    {

        if ($extension == 'xml') {
            if (self::checkIfTEI($file)) {
                $newTEIFile = self::putNewTeiHeaderinFile($file, self::createNewTEIHeader($fileId), true);
                Storage::disk('local')->put('/DLBTUploads/' . $fileName, $newTEIFile);
                return true;

            }
        } else if ($extension == 'zip') {
            $zip = new \ZipArchive;
            if ($zip->open(storage_path() . '/app/DLBTUploads/' . $fileName, \ZipArchive::CREATE) === TRUE) {
                if ($zip->locateName('tei.xml') !== false) {
                    $newTEIFile = self::putNewTeiHeaderinFile(self::getTeiFromZip($fileName, $zip), self::createNewTEIHeader($fileId), false);

                    $zip->deleteName('tei.xml');
                    $zip->addFromString('tei.xml', $newTEIFile);
                    $zip->close();

                    //delete temp directory...
                    $dirAndFileName = pathinfo($fileName, PATHINFO_DIRNAME);
                    if ($dirAndFileName == '.')
                        Storage::delete('/DLBTUploads/unzipped/temp/' . $fileName);
                    else
                        Storage::deleteDirectory('/DLBTUploads/unzipped/temp/' . $dirAndFileName);
                    return 1;
                }
            }

        }
    }

    /**
     * @param $file
     * @param $newTeiHeader
     * @param $getContent
     * @return string
     */
    private static function putNewTeiHeaderinFile($file, $newTeiHeader, $getContent)
    {

        if ($getContent == true)
            $TEIFile = file_get_contents($file);
        else
            $TEIFile = $file;

        $TEIFileOhne = substr($TEIFile, strpos($TEIFile, '<body>'), strlen($TEIFile));
        $TEIFileOhne = str_replace("<body>", '', $TEIFileOhne);
        $TEIFile = "<TEI version=\"5.0\" xmlns=\"http://www.tei-c.org/ns/1.0\">" . $newTeiHeader . "<text>\r\n   <body>\r\n" . $TEIFileOhne;

        return $TEIFile;

    }

    /**
     * @param $file
     * @param $zip
     * @return string
     */
    private static function getTeiFromZip($fileName, $zip)
    {
        $zip->extractTo(storage_path() . '/app/DLBTUploads/unzipped/temp/' . $fileName);
        $fileTei = storage::get('/DLBTUploads/unzipped/temp/' . $fileName . '/' . 'tei.xml');

        return $fileTei;
    }

    /**
     * @param $fileId
     * @return false|string|string[]
     */
    private static function createNewTEIHeader($fileId)
    {
        $recordId = File::where('id', $fileId)->pluck('ref_id');
        $record = Ref::find($recordId[0]);
        $TEIData = self::generateData4NewTEIHeader($record);
        $newTEIHeader = self::changeTEIHeader($TEIData);
        return $newTEIHeader;

    }

    /**
     * @param $TEIData
     * @return false|string|string[]
     */
    private static function changeTEIHeader($TEIData)
    {
        $Template = app_path() . '/includes/xml/TEIHeaderTmpl.xml';
        if (file_exists($Template)) {
            $TEITemplate = file_get_contents($Template);
            for ($i = 0; $i < count($TEIData[0]); $i++) {
                $TEITemplate = str_replace('###' . $TEIData[0][$i]['Name'] . '###', $TEIData[0][$i]['Value'], $TEITemplate);
            }

            return $TEITemplate;
        }
    }

    /**
     * @param $file
     * @return bool
     */
    private static function checkIfTEI($file)
    {
        $fileContent = $file->get();
        if (strpos($fileContent, 'teiHeader')) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param $record
     * @return array
     * @throws \Throwable
     */
    private static function generateData4NewTEIHeader($record)
    {
        $TEIArray = array();
        // generate Xenodata
        $TEIXenoDataMods = self::makeXenoDataset($record);
        //cut xml-header
        $TEIXenoDataMods = str_replace('<?xml version="1.0" encoding="UTF-8" ?>', '', $TEIXenoDataMods);
        $TEIXenoDataMods = str_replace("\n\n", "\n\t", $TEIXenoDataMods);

        $data['ref'] = $record;
        $title = trim(preg_replace('/\s+/', ' ', strip_tags(ExportController::reformatBladeExport(view('dlbt.styles.format_as_' . Style::getNameStyle(), $data)->render()))));


        $TEIArray[] = array(
            array("Name" => "TEITitle", "Value" => $title),
            array("Name" => "TEIAuthor", "Value" => $TEIAuthor = $record['author']),
            array("Name" => "TEICreationDate", "Value" => $TEICreationDate = $record['created_at']),
            array("Name" => "TEILicence", "Value" => ''), //ToDo Do we really want to use license here?
            array("Name" => "TEIIdentifier", "Value" => "DLBT_Upload"),
            array("Name" => "TEIXenodata", "Value" => $TEIXenoDataMods),
        );
        return $TEIArray;
    }

    /**
     * @param $record
     * @return false|string
     */
    private static function makeXenoDataset($record)
    {
        $record = $record->prepareDataset();
        $record['updated_at'] = $record['updated_at']->format('M d Y');
        $record = array($record);

        $xml = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><Collection></Collection>");

        $xml = ExportController::Array_to_xml($record, $xml);

        $xml = $xml->asXML();

        $xsl = app_path('Includes/xsl/dforms2mods.xsl');
        $xmlversion1 = simplexml_load_string($xml);

        $modsCollectionString = ExportController::throughXsl($xmlversion1, $xsl, "DLBT", "");
        return $modsCollectionString;
    }

    /**
     * @param $file
     * @return bool|string|null
     */
    public function convertXMLToHTML($file)
    {
        $fileWithFullPath = storage_path() . '/app/DLBTUploads/' . $file;

        $SaxonJarWithPath = app_path('Includes/Saxon/');
        $teiToHtmlXslWithPath = app_path('/Includes/xsl/Tei/stylesheet/html/');

        if (file_exists($fileWithFullPath)) {
            if (storage::exists('DLBTUploads/' . $file)) {
                $fileToCheckOnTEI = storage::get('DLBTUploads/' . $file);
            }

            if (strpos($fileToCheckOnTEI, 'teiHeader') !== false) {
                try {
                    $command = 'java -jar ' . $SaxonJarWithPath . 'saxon-he-10.1.jar';
                    $command .= ' -s:' . $fileWithFullPath . ' -xsl:';
                    $command .= $teiToHtmlXslWithPath . 'html.xsl';
                    $htmlFile = (shell_exec($command));
                    return $htmlFile;
                } catch (\Throwable $e) {
                    return 'Wrong Format';
                }
            } else {
                return 'Wrong Format';
            }

        } else {
            return 'File not found';
        }
    }

}
