<?php 

require_once('nucommon.php'); 
require_once('fpdf/fpdf.php');
define('FPDF_FONTPATH','fpdf/font/');

//error_reporting(E_ALL);
//ini_set('display_errors', 1);

$GLOBALS['nu_temp_table_id'] = '';
$GLOBALS['nu_built_sections'] = array();
$GLOBALS['nu_columns'] = array();
$GLOBALS['nu_files'] = array();

$reportQRY = nuRunQuery("SELECT deb_message AS json FROM zzzsys_debug WHERE zzzsys_debug_id = ? ", array($_GET['i']));
if(db_num_rows($reportQRY) == 0)
    die('Report data expired. Please reprint from the report criteria screen.');
$reportOBJ = db_fetch_object($reportQRY);
$reportOBJ = json_decode($reportOBJ->json);
$reportPropertiesFromDB = json_decode($reportOBJ->sre_layout);

nuRunQuery("DELETE FROM zzzsys_debug WHERE zzzsys_debug_id = ? ", array($_GET['i']));

$PDF = new FPDF($reportPropertiesFromDB->orientation, 'mm', $reportPropertiesFromDB->paper);
$PDF->SetAutoPageBreak(false);

$fonts = explode("\n", trim($GLOBALS['nuSetup']->set_fonts));
for($i=0; $i<count($fonts); $i++){
    if(trim($fonts[$i]) != ''){
        $PDF->AddFont($fonts[$i], '' , strtolower($fonts[$i]) . '.php');
        $PDF->AddFont($fonts[$i], 'B', strtolower($fonts[$i]) . '.php');
        $PDF->AddFont($fonts[$i], 'I', strtolower($fonts[$i]) . '.php');
    }
}

$reportPropertiesFromDB = nuReduceReportPixelRatio($reportPropertiesFromDB);

$PDF->SetMargins(1,1,1);

$GLOBALS['nu_temp_table_id'] = nuTT();
$hashData = nuBuildHashData($reportOBJ, $GLOBALS['nu_temp_table_id']);
if($reportOBJ->sre_zzzsys_sql == ''){
    $phpCode = nuReplaceHashes($reportOBJ->slp_php, $hashData);
    eval($phpCode);
} else {
    $createTableSQL = nuReplaceHashes($reportOBJ->sre_zzzsys_sql, $hashData);
    nuRunQuery('CREATE TABLE '.$GLOBALS['nu_temp_table_id'].' '.$createTableSQL);
}

$GLOBALS['nu_columns'] = nuAddCriteriaValuesToTempTable($hashData);

nuRunQuery("ALTER TABLE ".$GLOBALS['nu_temp_table_id']." ADD `nu__id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");

/*
    Build array($GLOBALS['nu_built_sections']) of sections containing top/height pixels and objects with coords calculated
*/
nuBuildReport($PDF, $reportPropertiesFromDB);

$hashData['nu_pages'] = nuGetTotalPages();
nuReplaceLabelHashVariables($reportPropertiesFromDB, $hashData);

/*
    Create the FPDF objects using $GLOBALS['nu_built_sections']
*/
nuPrintReport($PDF, $reportPropertiesFromDB, $GLOBALS['nu_built_sections'], $reportOBJ);

nuRunQuery("DROP TABLE IF EXISTS ".$GLOBALS['nu_temp_table_id']);
nuRunQuery("DROP TABLE IF EXISTS ".$GLOBALS['nu_temp_table_id'].'_nu_summary');
$PDF->Output('nureport.pdf', 'I');

nuRemoveFiles();

function nuPrintReport($PDF, $reportPropertiesFromDB, $builtSections, $reportOBJ){

    $lastSectionTop = 10000;
    $pageNumber     = 0;
    for($sectionNo = 0; $sectionNo < count($builtSections); $sectionNo++){
        if($lastSectionTop > $builtSections[$sectionNo]->sectionTop)
            $pageNumber++;
        $lastSectionTop = $builtSections[$sectionNo]->sectionTop;
        for($objectNo = 0; $objectNo < count($builtSections[$sectionNo]->objects); $objectNo++){
            $object = nuGetObjectProperties($reportPropertiesFromDB, $builtSections[$sectionNo]->objects[$objectNo]->id);
            if($object->objectType == 'label'){
                $label = $builtSections[$sectionNo]->objects[$objectNo]->lines[0];
                $label = str_replace('#page#', $pageNumber, $label);
                $label = str_replace('#pages#', nuGetTotalPages(), $label);
                $label = str_replace('#description#', $reportOBJ->sre_description, $label);
                $label = str_replace('#code#', $reportOBJ->sre_code, $label);
                $label = str_replace('#year#', date('y'), $label);
                $label = str_replace('#month#', date('m'), $label);
                $label = str_replace('#day#', date('d'), $label);
                $label = str_replace('#hour#', date('h'), $label);
                $label = str_replace('#minute#', date('i'), $label);
                $label = str_replace('#second#', date('s'), $label);   
                $builtSections[$sectionNo]->objects[$objectNo]->lines[0] = $label;
            }
        }
    }

    $lastSectionTop = 10000;
    for($sectionNo = 0; $sectionNo < count($builtSections); $sectionNo++){
        if($lastSectionTop > $builtSections[$sectionNo]->sectionTop){
            $PDF->AddPage();
        }
        $lastSectionTop = $builtSections[$sectionNo]->sectionTop;

        $color = $reportPropertiesFromDB->groups[$builtSections[$sectionNo]->group]->sections[$builtSections[$sectionNo]->section]->color;
        nuPrintBackground($PDF, $builtSections[$sectionNo]->sectionTop, $builtSections[$sectionNo]->sectionHeight, $color);
        
        for($objectNo = 0; $objectNo < count($builtSections[$sectionNo]->objects); $objectNo++){
            $object = nuGetObjectProperties($reportPropertiesFromDB, $builtSections[$sectionNo]->objects[$objectNo]->id);
            if($object->objectType == 'field' or $object->objectType == 'label'){
                nuPrintField($PDF, $builtSections[$sectionNo], $builtSections[$sectionNo]->objects[$objectNo], $object->id, $reportPropertiesFromDB);
            }
            if($object->objectType == 'image'){
                nuPrintImage($PDF, $builtSections[$sectionNo], $builtSections[$sectionNo]->objects[$objectNo], $object);                                                           //-- print graphic
            }
        }
        
    }
}

function nuPrintField($PDF, $builtSection, $builtObject, $builtObjectID, $reportPropertiesFromDB){

    $PROP = nuGetObjectProperties($reportPropertiesFromDB, $builtObjectID);
    $fontFamily = $PROP->fontFamily;
    $fontWeight = $PROP->fontWeight;
    $fontSize = $PROP->fontSize;
    $borderWidth = $PROP->borderWidth;
    $borderColor = $PROP->borderColor;
    $backgroundColor = $PROP->backgroundColor;
    $fontColor = $PROP->fontColor;
    $width = $PROP->width;
    $height = $PROP->height;
    $textAlign = strtoupper($PROP->textAlign[0]);
    $left = $PROP->left;
    $top = $builtSection->sectionTop + $builtObject->top;
    
    if(isset($builtObject->B)){$backgroundColor = $builtObject->B;}
    if(isset($builtObject->F)){$fontColor = $builtObject->F;}

    
    $PDF->SetFont($fontFamily, $fontWeight, $fontSize, '', false);
    $PDF->SetLineWidth($borderWidth / 5);

    $drawcolor = hex2rgb($borderColor);
    $backcolor = hex2rgb($backgroundColor);
    $textcolor = hex2rgb($fontColor);

    $PDF->SetDrawColor($drawcolor[0], $drawcolor[1], $drawcolor[2]);
    $PDF->SetTextColor($textcolor[0], $textcolor[1], $textcolor[2]);
    $PDF->SetXY($left, $top);
    $PDF->SetFillColor(255, 255, 255);
    $PDF->SetFillColor($backcolor[0], $backcolor[1], $backcolor[2]);
    
    $PDF->MultiCell($width, $height, implode("\n", $builtObject->lines), $borderWidth == 0 ? 0 : 1, $textAlign, true); 
    
}

function nuPrintImage($PDF, $S, $contents, $O){
    $top = $S->sectionTop + $contents->top;
    if(property_exists($O, 'path')){
        if($O->path != ''){
            $PDF->Image($O->path, $O->left, $top, $O->width, $O->height);
        }
    } else if(property_exists($O, 'filePath')){
        if($O->filePath != ''){
            $PDF->Image($O->filePath, $O->left, $top, $O->width, $O->height);
        }
    }
}

function nuBuildReport($PDF, $reportPropertiesFromDB){

    $groupBy = array();
    $groups = array();
    $sectionValue = array();
    $print_group = 1;
    $order_by = '';
    $group_by = '';
    $order['a'] = 'asc ';
    $order['d'] = 'desc ';

    nuMakeSummaryTable($reportPropertiesFromDB);
    
    for($i = 3; $i < 8; $i++){
        if($reportPropertiesFromDB->groups[$i]->sortField != ''){
            $order_by = ' ORDER BY ';
            $groupBy[] = $reportPropertiesFromDB->groups[$i]->sortField . ' ' . $order[$reportPropertiesFromDB->groups[$i]->sortBy];
            $groups[] = $reportPropertiesFromDB->groups[$i]->sortField;
            nuRunQuery("ALTER TABLE ".$GLOBALS['nu_temp_table_id']." ADD INDEX `".$reportPropertiesFromDB->groups[$i]->sortField."` (`".$reportPropertiesFromDB->groups[$i]->sortField."`)");
        }
    }
    $group_by = implode(',', $groupBy);
    $DATA = nuRunQuery("SELECT * FROM ".$GLOBALS['nu_temp_table_id']." $order_by $group_by");
    $ROW = db_fetch_array($DATA);

    $sectionTop = 0;

    //======================================================    
    //      REPORT HEADER
    //======================================================
    $S = new nuSECTION($PDF, $ROW, $reportPropertiesFromDB, $reportGroupID = 1, $headerSectionID = 0, 0);
    $sectionTop = $S->buildSection();
    $firstRecord = true;

    //======================================================    
    //      PAGE HEADER
    //======================================================    
    $S = new nuSECTION($PDF, $ROW, $reportPropertiesFromDB, $pageGroupID = 2, $headerSectionID = 0, $sectionTop);
    $sectionTop = $S->buildSection();
    $firstRecord = true;

    //======================================================    
    //      FIRST SECTION HEADERS
    //======================================================    
    for($g = 0; $g < count($groups); $g++){
        $S = new nuSECTION($PDF, $ROW, $reportPropertiesFromDB, $customGroupID = (3 + $g), $headerSectionID = 0, $sectionTop);
		 // If we are on the first header section for the first record, dont page break
		$S->forceIgnorePageBreak = true;
		$sectionTop = $S->buildSection();
        $sectionValue[$groups[$g]] = $ROW[$groups[$g]];
    }

    //======================================================    
    //      LOOP THROUGH TABLE
    //======================================================    
    $DATA = nuRunQuery("SELECT * FROM (SELECT * FROM ".$GLOBALS['nu_temp_table_id']." $order_by $group_by) AS tmp ");
    while($ROW = db_fetch_array($DATA)){
    
        if(!$firstRecord){
            
            $backUpTo = nuLowestGroupChange($sectionValue, $ROW, $groups);
            
            //======================================================    
            //      FOOTERS AND HEADERS AS GROUPS CHANGE
            //======================================================    
            for($g = count($groups) - 1; $g >= $backUpTo; $g--){
                $S = new nuSECTION($PDF, $lastROW, $reportPropertiesFromDB, $customGroupID = (3 + $g), $footerSectionID = 1, $sectionTop);
                $sectionTop = $S->buildSection();
                $sectionValue[$groups[$g]] = $ROW[$groups[$g]];
            }
            for($g = $backUpTo; $g < count($groups); $g++){
                $S = new nuSECTION($PDF, $ROW, $reportPropertiesFromDB, $customGroupID = (3 + $g), $headerSectionID = 0, $sectionTop);
                $sectionTop = $S->buildSection();
                $sectionValue[$groups[$g]] = $ROW[$groups[$g]];
            }
            
        }
    
        //======================================================    
        //      DETAIL SECTION
        //======================================================    
        $S = new nuSECTION($PDF, $ROW, $reportPropertiesFromDB, $detailGroupID = 0, $headerSectionID = 0, $sectionTop);
        $sectionTop = $S->buildSection();
        $lastROW = $ROW;
        $firstRecord = false;

    }
    
    //======================================================    
    //      LAST GROUP FOOTERS
    //======================================================    
    for($g = count($groups) - 1; $g > -1; $g--){
        $S = new nuSECTION($PDF, $lastROW, $reportPropertiesFromDB, $customGroupID = (3 + $g), $footerSectionID = 1, $sectionTop);
        $sectionTop = $S->buildSection();
    }
            
    //======================================================    
    //      REPORT FOOTER
    //======================================================    
    $S = new nuSECTION($PDF, $lastROW, $reportPropertiesFromDB, $reportGroupID = 1, $footerSectionID = 1, $sectionTop);
    $sectionTop = $S->buildSection();

}    




function nuLowestGroupChange($lastROW, $thisROW, $groups){
    
    $lastString              = '';
    $thisString              = '';
    
    for($g = 0 ; $g < count($groups) ; $g ++){
        
        if($lastROW[$groups[$g]] != $thisROW[$groups[$g]]){
            
            return $g;
            
        }
    }
    
    return count($groups);
    
}

function pdfSection($g, $s, $t, $h){

    $c                = new stdClass;  
    $c->objects       = array();
    $c->group         = $g;
    $c->section       = $s;
    $c->sectionTop    = $t;
    $c->sectionHeight = $h;

    return $c;

}

function pdfObject($id, $t){
    $c = new stdClass;  
    $c->lines = array();
    $c->id = $id;
    $c->top = $t;
    $c->path = '';
    return $c;
}


function nuReduceReportPixelRatio($reportPropertiesFromDB){
    $ratio = .25;
    for($g=0; $g<count($reportPropertiesFromDB->groups); $g++){
        for($s=0; $s<count($reportPropertiesFromDB->groups[$g]->sections); $s++){
            $reportPropertiesFromDB->groups[$g]->sections[$s]->height = $reportPropertiesFromDB->groups[$g]->sections[$s]->height * $ratio;
            for($o=0; $o<count($reportPropertiesFromDB->groups[$g]->sections[$s]->objects); $o++){
                $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->fontSize = $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->fontSize;
                $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->height = $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->height * $ratio;
                $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->left = $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->left * $ratio;
                $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->top = ($reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->top) * $ratio;
                $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->width = $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->width * $ratio;
            }
        }
    }
    return $reportPropertiesFromDB;
}




function nuPrintBackground($PDF, $sectionTop, $sectionHeight, $color){

    $backcolor                   = hex2rgb($color);
    
    $PDF->SetFillColor($backcolor[0], $backcolor[1], $backcolor[2]);
    $PDF->Rect(0, $sectionTop, 1000, $sectionHeight, 'F');

}



function nuGetObjectProperties($reportPropertiesFromDB, $id){
    for($g = 0 ; $g < count($reportPropertiesFromDB->groups) ; $g++){
        for($s = 0 ; $s < count($reportPropertiesFromDB->groups[$g]->sections) ; $s++){
            for($o = 0 ; $o < count($reportPropertiesFromDB->groups[$g]->sections[$s]->objects) ; $o++){
                if($reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o]->id   == $id)
                    return $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o];
            }
        }
    }
    return '';
}



function nuGetTotalPages(){
    $pages = 0;
    for($i = 0; $i < count($GLOBALS['nu_built_sections']); $i++){
        if($GLOBALS['nu_built_sections'][$i]->sectionTop == 0){
            $pages++;
        }
    }
    return $pages;
}


function nuReplaceLabelHashVariables($reportPropertiesFromDB, $hashData){
    for($i = 0; $i<count($GLOBALS['nu_built_sections']); $i++){
        for($o = 0; $o < count($GLOBALS['nu_built_sections'][$i]->objects); $o++){
            $O = nuGetObjectProperties($reportPropertiesFromDB, $GLOBALS['nu_built_sections'][$i]->objects[$o]->id);
            if($O->objectType == 'label'){
                for($l = 0 ; $l < count($GLOBALS['nu_built_sections'][$i]->objects[$o]->lines) ; $l++){
                    $GLOBALS['nu_built_sections'][$i]->objects[$o]->lines[$l] = nuReplaceHashes($GLOBALS['nu_built_sections'][$i]->objects[$o]->lines[$l], $hashData);
                }
            }
        }
    }
}

function nuMakeSummaryTable($reportPropertiesFromDB){
    
    $sumFieldNames = array();
    $sumFields = array();
    
    for($i = 3; $i < 8; $i++){
        if($reportPropertiesFromDB->groups[$i]->sortField != ''){              //-- loop through groups
            $groupFields[] = $reportPropertiesFromDB->groups[$i]->sortField;
        }
    }
    
    for($g = 0 ; $g < count($reportPropertiesFromDB->groups) ; $g++){
        for($s = 0 ; $s < count($reportPropertiesFromDB->groups[$g]->sections) ; $s++){
            for($o = 0 ; $o < count($reportPropertiesFromDB->groups[$g]->sections[$s]->objects) ; $o++){
                $obj = $reportPropertiesFromDB->groups[$g]->sections[$s]->objects[$o];
                if($obj->objectType == 'field'){
                    if(strtoupper(substr($obj->fieldName,0,4)) == 'SUM('){
                        $sumFieldNames[substr($obj->fieldName,4, -1)]  = substr($obj->fieldName,4, -1);
                    }
                    if(strtoupper(substr($obj->fieldName,0,8)) == 'AVERAGE('){
                        $ex = explode(',',substr($obj->fieldName, 8, -1));
                        $sumFieldNames[trim($ex[0])] = trim($ex[0]);
                        $sumFieldNames[trim($ex[1])] = trim($ex[1]);
                    }
                }
            }
        }
    }
    
    foreach ($sumFieldNames as $k => $v) {
        $sumFields[] = "SUM(`$v`) AS `nu_sum_$v` ";
    }

    if(count($sumFields) > 0 and count($groupFields) > 0){   //-- a reason to have a summary table
        nuRunQuery("CREATE TABLE ".$GLOBALS['nu_temp_table_id']."_nu_summary SELECT count(*) as nu_count, " . implode(',',$sumFields) . ", " . implode(',',$groupFields) . " FROM ".$GLOBALS['nu_temp_table_id']." group by " . implode(',',$groupFields));
        for($i = 0; $i < count($groupFields); $i++){
            nuRunQuery("ALTER TABLE ".$GLOBALS['nu_temp_table_id']."_nu_summary  ADD INDEX `".$groupFields[$i]."` (`".$groupFields[$i]."`)");
        }
    }
}


function nuAddCriteriaValuesToTempTable($hashData){

    $T = $GLOBALS['nu_temp_table_id'];
    $c = db_columns($T);
    $a = array();

    foreach($hashData as $key => $value){
        if(!in_array($key, $c) and !is_array($value) and !is_object($value) ){
            $v = substr(addslashes($value),0,199);
            if(substr($v,(strlen($v)-1),1) == '\\')
                $v = substr($v,0,strlen($v)-1);
            $l = min(strlen($v), 200);
            if($l > 0){
                $a[] = " ADD `$key` VARCHAR($l) DEFAULT '$v' ";
            }
            $c[] = strtolower($key);
        }
    }
    
    if(count($a) > 0){
        nuRunQuery("ALTER TABLE $T " . implode(',', $a)); 
    }
    
    return $c;
    
}

function nuIsField($fieldName){
    return in_array($fieldName, $GLOBALS['nu_columns']);
}

function nuCheckFileByCode($fileCode){
    $t = nuRunQuery("SELECT zzzsys_file_id FROM zzzsys_file WHERE sfi_code = ? ", array($fileCode));
    $r = db_fetch_object($t);
    
    if($r=='') return false;
    else return $r->zzzsys_file_id != '';
}


function nuCheckFileByURL($url){
    $file_headers = @get_headers($url);

    if($file_headers[0] == 'HTTP/1.0 404 Not Found'){
          return false;
    } else if ($file_headers[0] == 'HTTP/1.0 302 Found'){
          return false;
    } else {
          return true;
    }    
}


function nuRemoveFiles(){
    for($i = 0 ; $i < count($GLOBALS['nu_files']) ; $i++){
        unlink($GLOBALS['nu_files'][$i]);
    }
}

/*
    The goal of this class is to populate $GLOBALS['nu_built_sections'] with an array of sections containing simply top / height pixel coords and objects inside coords
    nuSECTION class instance is run for each groups header / footer and detail FOR EACH ROW in the table

    For a report with 1 row in the temp table, and 1 custom group example (nuSECTION class will be instantiated for each below):
    group = 1 section = 0 // report header
    group = 2 section = 0 // page header
    group = 3 section = 0 // custom group header, note that groups 3 and >=3 are custom groups
    group = 0 section = 0 // detail section is technically a header only
    group = 3 section = 1 // custom group footer
    group = 2 section = 1 // page footer
    group = 1 section = 1 // report footer

    If any nuSECTION instance is too big and crosses a page, multiple sections will be created for a single nuSECTION instance (kept in $this->SECTIONS)
    Page breaks also add to $this->SECTIONS
    So 1 nuSECTION object might add 3 sections to $GLOBALS['nu_built_sections'] for example
*/
class nuSECTION {

    public $group = 0;
    public $section = 0;
    public $sectionHeight = 0;
    public $sectionTop = 0;
    public $O = array();
    public $PDF = array();
    public $ROW = array();
    public $reportPropertiesFromDB = array();
    public $SECTIONS = array(); //-- this Section split over pages
    public $OBJECTS = array();
	public $forceIgnorePageBreak = false;
	
	
    function __construct($PDF, $ROW, $reportPropertiesFromDB, $groupID, $sectionType, $sectionTop){
        $this->PDF = $PDF;
        $this->ROW = $ROW;
        $this->reportPropertiesFromDB = $reportPropertiesFromDB;
        $this->TABLE_ID = $GLOBALS['nu_temp_table_id'];
        $this->group = $groupID;
        $this->section = $sectionType;
        $this->sectionObjects = $this->reportPropertiesFromDB->groups[$groupID]->sections[$sectionType]->objects;
        $this->pageHeight = $this->reportPropertiesFromDB->height;
        $this->sectionTop = $sectionTop;
        $this->sectionHeight = $this->reportPropertiesFromDB->groups[$groupID]->sections[$sectionType]->height;
    }

    public function buildSection(){
        $this->sectionObjects = $this->processObjects($this->sectionObjects);
        $nextTop = $this->chopSectionOverPages();
        $GLOBALS['nu_built_sections'] = array_merge($GLOBALS['nu_built_sections'], $this->SECTIONS);
        return $nextTop;
    }
    
    
    private function nuGetFormatting($Object){
        $f = array();
        $f['B'] = '';
        $f['F'] = '';
        $f['S'] = '';
        $v = $this->ROW[$Object->fieldName];
        // example format #B#66FF99|
        // example multiple format #B#66FF99|#F#FF0000|
        while(substr($v,0,1) == '#' and substr($v,2,1) == '#' and substr($v,9,1) == '|'){
            $f[strtoupper(substr($v,1,1))] = substr($v,3,6);
            $v = substr($v,10);
        }
        $f['V'] = $v;
        return $f;
    }
    
    
    private function processObjects($sectionObjects, $stopGrow = false){
        for($i = 0; $i < count($sectionObjects); $i++){
            $sectionObjects[$i]->filePath = '';
            if($sectionObjects[$i]->objectType == 'field' or $sectionObjects[$i]->objectType == 'label'){
                if(is_array($this->ROW)){
                    if(array_key_exists($sectionObjects[$i]->fieldName, $this->ROW)){
                        if($sectionObjects[$i]->objectType == 'field'  and substr($this->ROW[$sectionObjects[$i]->fieldName],0,1) == '#' and substr($this->ROW[$sectionObjects[$i]->fieldName],2,1) == '#' and substr($this->ROW[$sectionObjects[$i]->fieldName],9,1) == '|'){
                            $format = $this->nuGetFormatting($sectionObjects[$i]);
                            if($format['B'] != ''){
                                $sectionObjects[$i]->B = '#' . $format['B'];
                            }
                            if($format['F'] != ''){
                                $sectionObjects[$i]->F = '#' . $format['F'];
                            }
                        }
                    }
                }
                $sectionObjects[$i]->LINES = $this->getObjectRows($sectionObjects[$i], $stopGrow);
            } else if($sectionObjects[$i]->objectType == 'image'){
                $sectionObjects[$i]->LINES = array('');
                $filePath = '';
                $imageValue = $sectionObjects[$i]->image;
                if(nuIsField($imageValue)){
                    $imageFromTableValue = $this->ROW[$imageValue];
                    if(nuCheckFileByCode($imageFromTableValue)){
                        $filePath = nuCreateFile($imageFromTableValue);
                        $GLOBALS['nu_files'][] = $filePath;
                    }else if(nuCheckFileByURL($imageFromTableValue)){
                        $filePath = $imageFromTableValue;
                    }
                }else{
                    if(nuCheckFileByCode($imageValue)){
                        $filePath = nuCreateFile($imageValue);
                        $GLOBALS['nu_files'][]  = $filePath;
                    }else if(nuCheckFileByURL($imageValue)){
                        $filePath = $imageValue;
                    }
                }
                $sectionObjects[$i]->filePath = $filePath;
            }
        }
        return $sectionObjects;
    }

    private function chopSectionOverPages(){
        $sectionObjectIDs = array();
        $sectionTop = $this->sectionTop;
        $objectParts = array();
        $pages = 0;
        $expandedSectionHeight = $this->sectionHeight + $this->extendedHeight() - .25;
        $pageBreak = 0;
        if(property_exists($this->reportPropertiesFromDB->groups[$this->group]->sections[$this->section], 'page_break')){
            $pageBreak = $this->reportPropertiesFromDB->groups[$this->group]->sections[$this->section]->page_break;
        }

        for($i = 0; $i < count($this->sectionObjects); $i++){
            $sectionObjectIDs[] = $this->sectionObjects[$i]->id;
        }

        for($i = 0; $i < count($this->sectionObjects); $i++){
            $objectID = $this->sectionObjects[$i]->id;
            $availableHeight = $this->paperBottom() - $sectionTop - $this->sectionObjects[$i]->top;
            while(count($this->sectionObjects[$i]->LINES) > 0){
                if(($this->paperBottom() - $sectionTop < $this->sectionHeight)){
                    // '10' is report header
                    if("$this->group$this->section" != '10'){
                        $this->pageHeaderFooter($footerSectionID = 1);
                        $this->pageHeaderFooter($headerSectionID = 0);
                    }
                    $sectionTop = $this->paperTop();
                    $availableHeight = $this->paperBottom() - $this->paperTop();
                }
                if($this->sectionObjects[$i]->height == 0){
                    $fit = $availableHeight;
                }else{
                    $fit = floor($availableHeight / $this->sectionObjects[$i]->height);
                }
                $PDFObject = pdfObject($this->sectionObjects[$i]->id, $this->sectionObjects[$i]->top);
                if(isset($this->sectionObjects[$i]->B)){$PDFObject->B = $this->sectionObjects[$i]->B;$this->sectionObjects[$i]->B = null;}
                if(isset($this->sectionObjects[$i]->F)){$PDFObject->F = $this->sectionObjects[$i]->F;$this->sectionObjects[$i]->F = null;}
                $fittingLines = array_splice($this->sectionObjects[$i]->LINES, 0, $fit);
                $PDFObject->lines = $fittingLines;
                if(property_exists($this->sectionObjects[$i], 'path')){
                    $PDFObject->path = $this->sectionObjects[$i]->path;
                } else {
                    $PDFObject->path = '';
                }
                if($fit > 0){
                    $objectParts[$objectID][] = $PDFObject;
                }else{
                    $sectionTop = $this->paperTop();
                }
                $availableHeight = $this->paperBottom() - $this->paperTop();
            }
            $pages = Max($pages, count($objectParts[$objectID]));
        }

        for($i = 0; $i < $pages; $i++){
            $sectionTop = $i == 0 ? $sectionTop : $this->paperTop();
            $sectionHeight = $i + 1 == $pages ? $expandedSectionHeight : $this->paperBottom() - $sectionTop;
            $expandedSectionHeight = $expandedSectionHeight - $sectionHeight;
            $PDFSection = pdfSection($this->group, $this->section, $sectionTop, $sectionHeight);
            $sectionTop = $sectionTop + $sectionHeight;
            $sectionObjects = array();
            for($obj = 0; $obj < count($sectionObjectIDs); $obj++){
                if(count($objectParts[$sectionObjectIDs[$obj]]) <= $pages){
                    $sectionObjects[] = $objectParts[$sectionObjectIDs[$obj]][$i];
                }
            }
            $PDFSection->objects = $sectionObjects;
            $this->SECTIONS[] = $PDFSection;
            if($expandedSectionHeight > 0){
                $expandedPDFSection = pdfSection($this->group, $this->section, $sectionTop, $expandedSectionHeight);
                $sectionTop = $sectionTop + $expandedSectionHeight;
                $sectionObjects = array();
                $expandedPDFSection->objects = $sectionObjects;
                $this->SECTIONS[] = $expandedPDFSection;
            }
            if($sectionTop >= $this->paperBottom()){
                // '10' is report header
                if("$this->group$this->section" != '10'){
                    $this->pageHeaderFooter($footerSectionID = 1);
                    if($i + 1 != $pages){
                        $this->pageHeaderFooter($headerSectionID = 0);
                    }
                }
            }
        }

        if($pageBreak == 1){
            $ignorePageBreak = false;
            // check if skipping page break
            if( $this->forceIgnorePageBreak ){ 
                $ignorePageBreak = true;
            }
			// if we are on the final footer sections, dont page break
            if($this->group >= 3 && $this->section == 1){
                // reverse the order of our groups to find the last row
                $order['d'] = 'asc ';
                $order['a'] = 'desc ';
                for($i = 3; $i < 8; $i++){
                    if($this->reportPropertiesFromDB->groups[$i]->sortField != ''){
                        $order_by = ' ORDER BY ';
                        $groupBy[] = $this->reportPropertiesFromDB->groups[$i]->sortField . ' ' . $order[$this->reportPropertiesFromDB->groups[$i]->sortBy];
                    }
                }
                $group_by = implode(',', $groupBy);
                $lastRowQRY = nuRunQuery("SELECT * FROM ".$GLOBALS['nu_temp_table_id']." $order_by $group_by LIMIT 1 ");
                if(db_num_rows($lastRowQRY) > 0){
                    $lastRowOBJ = db_fetch_object($lastRowQRY);
                    $currentSortField = $this->reportPropertiesFromDB->groups[$this->group]->sortField;
                    if($this->ROW[$currentSortField] == $lastRowOBJ->$currentSortField)
                        $ignorePageBreak = true;
                }
            }
            if(!$ignorePageBreak){
                // '10' is report header
                if("$this->group$this->section" != '10'){
                    $this->pageHeaderFooter($footerSectionID = 1);
                    $this->pageHeaderFooter($headerSectionID = 0);
                }
                $sectionTop = $this->paperTop();
                $availableHeight = $this->paperBottom() - $this->paperTop();
            }
            $pageBreak = 0;
        }

        // after the final report footer, also print the page footer
        // '11' is report footer
        if("$this->group$this->section" == '11'){
            $this->pageHeaderFooter($footerSectionID = 1);
        }
        return $sectionTop;
    }


    
    private function pageHeaderFooter($sectionType){
        $sectionFromDB = $this->reportPropertiesFromDB->groups[2]->sections[$sectionType];
        $objects = $this->processObjects($sectionFromDB->objects, true);
        $newPDFObjects = array();
        
        for($i = 0; $i < count($objects); $i ++){
            $newPDFObject = pdfObject($objects[$i]->id, $objects[$i]->top);
            $newPDFObject->lines = $objects[$i]->LINES;
            $newPDFObjects[] = $newPDFObject;
        }
        
        $newPDFSection = pdfSection($pageGroupID = 2, $sectionType, $sectionType == ($headerSectionID = 0) ? 0 : $this->pageHeight - $sectionFromDB->height, $sectionFromDB->height);
        $newPDFSection->objects = $newPDFObjects;
        $this->SECTIONS[] = $newPDFSection;
    }
    
    private function extendedHeight(){
        if(count($this->sectionObjects) == 0){return 0;}
        $bottomMostObject          = 0;
        $bottomID                  = 0;
        for($i = 0; $i < count($this->sectionObjects); $i++){
            $thisBottom = $this->sectionObjects[$i]->top + (count($this->sectionObjects[$i]->LINES) * $this->sectionObjects[$i]->height);
            if($bottomMostObject < $thisBottom){
                $bottomID = $i;
                $bottomMostObject  = $thisBottom;
            }
        }
        return (count($this->sectionObjects[$bottomID]->LINES) - 1) * $this->sectionObjects[$bottomID]->height;
    }

    private function paperTop(){
        if(($this->group == ($reportGroupID = 1) or $this->group == ($pageGroupID = 2)) and $this->section == ($headerSectionID = 0)){
            return 0;
        } else {
            return $this->reportPropertiesFromDB->groups[2]->sections[0]->height+1; // page header + 1
        }
    }
    
    private function paperBottom(){
        if(($this->group == ($reportGroupID = 1) and $this->section == ($headerSectionID = 0)) or ($this->group == ($pageGroupID = 2) and $this->section == ($footerSectionID = 1))){
            return $this->pageHeight;
        }else{
            return $this->pageHeight - $this->reportPropertiesFromDB->groups[2]->sections[1]->height; // page footer
        }
    }
    
    private function getObjectRows($Object, $stopGrow){
        $rows = array();
        if($Object->objectType == 'field'){
            $text = $this->nuGetFieldValue($Object);
            $lineNoNR = str_replace ("\n\r", "\r", $text);
            $lineNoRN = str_replace ("\r\n", "\r", $lineNoNR);
            $lineJustR = str_replace ("\n", "\r", $lineNoRN);
            $lines = explode("\r", $lineJustR);
        }else{
            $lines = array($Object->fieldName);
        }

        for($i = 0; $i < count($lines); $i++){
            $thisLine = $lines[$i];
            $forceRow = true;
            while(strlen($thisLine) > 0){
                $result = $this->splitValueOverLine($thisLine, $Object);
                $rows[] = trim($result[0]);
                $thisLine = $result[1];
                $forceRow = false;
            }
            if($forceRow){
                $rows[] = '';
                $thisLine = '';
            }
        }
        
        if($stopGrow){
            return array($rows[0]);
        }
        if($Object->minRows > 0){
            while (count($rows) < $Object->minRows){
                $rows[] = ' ';
            }
        }
        if($Object->maxRows > 0){
            $rows = array_splice($rows, 0, $Object->maxRows);
        }
        if($Object->minRows == -1 and $rows[0] == '' ){
            $rows = array();
        }
        return $rows;
    }


    private function splitValueOverLine($text, $Object){
        //-- return an array 
        //-- 0 = a line that fits within the width of the Object        
        //-- 1 = remaining part of the paragraph
        $this->PDF->SetFont($Object->fontFamily, $Object->fontWeight, $Object->fontSize);
        if($Object->width - 2 > $this->PDF->GetStringWidth($text)){
            return array($text, '');
        }
        $to = 1;
        while($Object->width - 2 > $this->PDF->GetStringWidth(substr($text, 0, $to))){
            $to++;
        }
        $widestLine = substr($text, 0, $to);
        $foundSeperator = false;
        $foundLongestWord = false;
        $wordSplit = $to;
        for($i = strlen($widestLine) - 1; $i > 1; $i--){
            if(!$foundLongestWord && $this->PDF->GetStringWidth(substr($widestLine, 0, $i)) < $Object->width - 2){
                $wordSplit = $i;
                $foundLongestWord = true;
            }
            $break = strpos(", ;-", $widestLine[$i]);
            if($break !== false){
                $to = $i;
                $foundSeperator = true;
                break;
            }
        }
        if (!$foundSeperator)
            $to = $wordSplit;
        $remaining = substr($text, $to);
        $line = substr($text, 0, $to);
        return array($line, $remaining);
    }
    
    private function nuGetFieldValue($Object){
        $type = '';
        $value = '';
        if(strtoupper(substr($Object->fieldName,0,4)) == 'SUM('){
            $type = 's';
            $field = substr($Object->fieldName,4, -1);
        }
        if(strtoupper(substr($Object->fieldName,0,8)) == 'AVERAGE('){
            $type = 'p';
            $fields = explode(',', substr($Object->fieldName, 8, -1));
        }

        if($type == ''){ 
            if(is_array($this->ROW)){       
                if(array_key_exists($Object->fieldName, $this->ROW)){
                    $v = $this->nuGetFormatting($Object);
                    $value = mb_convert_encoding($v['V'], "WINDOWS-1252", "UTF-8");
                }
            }
        } else {
            $groups = array();
            $where = '';
            if($type == 'p'){
                $count = 'SUM(nu_sum_'.trim($fields[0]).') AS the_sum_a, SUM(nu_sum_'.trim($fields[1]).') AS the_sum_b';
            } else {
                $count = 'SUM(nu_sum_'.trim($field).') AS the_sum_a';
            }
            for($i = 3; $i <= $this->group; $i++){
                $groups[] = $this->reportPropertiesFromDB->groups[$i]->sortField . " = '" . str_replace("'", "\\'", $this->ROW[$this->reportPropertiesFromDB->groups[$i]->sortField] ) . "'";
                $where = ' WHERE ';
            }
            $sql = "SELECT $count FROM $this->TABLE_ID"."_nu_summary $where " . implode(' AND ',$groups);
            $t = nuRunQuery($sql);
            $r = db_fetch_row($t);
            if(array_key_exists(1, $r)){
                if($r[1] == 0 and $type == 'p'){
                    $value = 0;
                } else {
                    if($type == 'p'){
                        $value  = ($r[0] / $r[1]);
                    } else {
                        $value  = $r[0];
                    }
                }
            } else {
                $value  = $r[0];
            }
        }
        
        if($Object->format != ''){
            $format = nuTextFormats();
            $datatype = $format[$Object->format]->type;
            if($datatype == 'date'){
                if($value != '0000-00-00' && $value != ''){
                    $value = date($format[$Object->format]->phpdate,strtotime($value));
                } else {
                    $value = '';
                }
            }
            if($datatype == 'number'){
                $value = number_format($value , $format[$Object->format]->format ,$format[$Object->format]->decimal , $format[$Object->format]->separator);
            }
        }
        return $value;
    }        
}

?>