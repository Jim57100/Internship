<?php

namespace batigardecorps2018\fournisseurs\dc\tools;

use AT\Tools\ATBooleanTools;
use batigardecorps2014\tools\TarifGCTools;
use batigardecorps2018\core\modules\contexteInfo\GCContextInfoModule;
use batigardecorps2018\core\modules\globalJointures\GCGlobalJointures;
use batigardecorps2018\core\modules\globalJointures\GCJointureTools;
use batigardecorps2018\core\modules\globalJointures\jointureItemDescriptor\GCJointureDescriptor;
use batigardecorps2018\core\modules\maconnerie\contentItems\AbstractGCMaconnerieContentItemGC;
use batigardecorps2018\core\modules\maconnerie\contentItems\GCMaconnerieContentItemGCDroit;
use batigardecorps2018\core\modules\maconnerie\contentItems\GCMaconnerieContentItemGCRampant;
use batigardecorps2018\core\modules\properties\modeles\modeleRSM\GCModeleRSMModule;
use batigardecorps2018\core\modules\properties\tole\GCToleModule;
use batigardecorps2018\core\modules\segments\poseTableau\GCPoseTableauModule;
use batigardecorps2018\core\modules\segments\segmentExtremumInfo\GCSegmentExtremumInfo;
use batigardecorps2018\core\tools\GCTools;
use batigardecorps2018\fournisseurs\dc\modules\croixStAndre\DCGCCroixStAndreModule;
use com\database\table\advancedQuery\TablesQueryAdvanced;
use com\framework\genericModules\AFBasicModeleOptionModule;
use com\framework\product\AFModelGetter;
use com\framework\wrapper\AFModuleAdvanced;
use com\framework\wrapper\multiColorationModule\AFMulticolorationModule;
use Exception;

class DCGCTools
{
    //////////////
    // CONSTANTS
    //////////////

    //const STAIRS = 'escalier';
    //const STRAIGHT = 'droit';

    const TYPE_POSE_STAIRS = 'typePoseRampant';
    const TYPE_POSE_STRAIGHT = 'typePoseDroit';

    // Type of handrail attachment
    const REF_ANGLE_STRAIGHT_VARIABLE = 'angleDroitVariable';
    const REF_ANGLE_VERTICAL_STRAIGHT = 'angleVerticalDroit';
    const REF_ANGLE_VERTICAL_VARIABLE = 'angleVerticalVariable';
    const REF_ANGLE_DROIT = 'angleDroit';
    const REF_ANGLE_VARIABLE = 'angleVariable';
    const REF_ANGLE_VERTICAL = 'angleVertical';

    const REF_TABLE_PRICING_WALL_BRACKET_TABLE_POSE = 'tarifsFixationsMurales';
    const REF_TABLE_PRICING_MODEL_TABLE_POSE = 'tarifPoseTableau';
    const REF_TABLE_PRICING_GLASS_MARGIN = 'tarifsMarginVitrage';
    const REF_TABLE_PRICING_GLASS_MODEL = 'tarifsVitrage';
    const REF_TABLE_FINISHES = 'tarifsFinition';
    const REF_TABLE_PRICING_METAL_SHEET = 'tarifsTole';

    const REF_ITEM_METAL_SHEET = 'tole';
    const REF_ITEM_BARS = 'remplissage-barreaux';


    ///////////////////////////////////
    // METHODS
    ///////////////////////////////////

    ////////////
    // GETTERS
    ////////////

    /**
     * Prix du remplissage barreau
     * PV = Plus Value
     * @param $isStair
     * @param AFModelGetter $modelGetter
     * @return string
     * @throws Exception
     */
    public static  function getPriceRailing($isStair, AFModelGetter $modelGetter): string
    {
        $specificsData = ['IS_RAMPANT_CONTEXT' => ATBooleanTools::getStringOuiNon($isStair)];
        $price = DCGCTools::getTarifByDatas('tarif', $specificsData, $modelGetter);
        $basePriceRailing = $price['PRIX'];
        if ($isStair) {
            list($pvRampant, $uniteRampant,) = DCGCTools::getDatasPrixOption($modelGetter, 'PV_RAMPANT', $specificsData);
            if (isset($pvRampant) && $pvRampant > 0) {
                GCTools::applyPVToPrice($basePriceRailing, $pvRampant, $uniteRampant);
            }
        }
        return $basePriceRailing;
    }

    /**
     * Prix du poteau intermédiaire
     * @param $modelGetterGlobal
     * @return int
     * @throws Exception
     */
    public static  function getPriceIntermediatePost($modelGetterGlobal): int
    {
        $price = GCTools::getTarifByDatas('poteauInter', [], $modelGetterGlobal);
        return $price['PRIX'];
    }

    /**
     * Prix des poteaux d'extrémité
     * @param $modelGetterGlobal
     * @return int
     * @throws Exception
     */
    public static  function getPriceEndPost($modelGetterGlobal): int
    {
        $price = GCTools::getTarifByDatas('poteauExtremite', [], $modelGetterGlobal);
        return $price['PRIX'];
    }

    /**
     * Prix du poteau d'angle
     * @param $modelGetterGlobal
     * @return int
     * @throws Exception
     */
    public static  function getPricePostAngle($modelGetterGlobal): int
    {
        $price = GCTools::getTarifByDatas('poteauAngle', [], $modelGetterGlobal);
        return $price['PRIX'];
    }

    /**
     * Prix personnalisations des tôles
     * @param $section
     * @return array
     * @throws Exception
     */
    public static  function getMetalSheetPrice($section): array
    {
        $price = GCTools::getTarifByDatas(self::REF_TABLE_PRICING_METAL_SHEET, [], $section);
        return [$price['PRIX'], $price['UNIT']];
    }

    /**
     * Prix du vitrage
     * @param $modelGetterSection
     * @param $isStair
     * @return array
     * @throws Exception
     */
    public static  function getPriceGlass($modelGetterSection, $isStair): array
    {
        $price = GCTools::getTarifByDatas(self::REF_TABLE_PRICING_GLASS_MODEL, [], $modelGetterSection);
        if ($isStair) {
            $specificsData = ['IS_RAMPANT_CONTEXT' => ATBooleanTools::getStringOuiNon($isStair)];
            list($pvRampant, $uniteRampant,) = DCGCTools::getDatasPrixOption($modelGetterSection, 'PV_RAMPANT', $specificsData);
            if (isset($pvRampant) && $pvRampant > 0) {
                GCTools::applyPVToPrice($price['PRIX'], $pvRampant, $uniteRampant);
            }
        }
        return [$price['PRIX'], $price['UNIT']];
    }

    /**
     * Prix du Vitrage avec marges latérales
     * @param $modelGetterSection
     * @param $isStair
     * @return array
     * @throws Exception
     */
    public static  function getPriceGlassMargin($modelGetterSection, $isStair): array
    {
        $price = GCTools::getTarifByDatas(self::REF_TABLE_PRICING_GLASS_MARGIN, [], $modelGetterSection);
        if ($isStair) {
            $specificsData = ['IS_RAMPANT_CONTEXT' => ATBooleanTools::getStringOuiNon($isStair)];
            list($pvRampant, $uniteRampant,) = DCGCTools::getDatasPrixOption($modelGetterSection, 'PV_RAMPANT', $specificsData);
            if (isset($pvRampant) && $pvRampant > 0) {
                GCTools::applyPVToPrice($price['PRIX'], $pvRampant, $uniteRampant);
            }
        }
        return [$price['PRIX'], $price['UNIT']];
    }

    /**
     * Prix Croix de Saint André
     * @param $railingHasPVColor
     * @param $modelGetterGlobal
     * @param $totalProjectPrice
     * @return float
     * @throws Exception
     */
    public static  function getPriceStAndrewCross($railingHasPVColor, $modelGetterGlobal, $totalProjectPrice): float
    {
        $price = GCTools::getTarifByDatas('tarifsAccessoires', ['ref_accessoire' => 'croixStAndre'], $modelGetterGlobal);
        if ($railingHasPVColor) {
            list($pricePV, $unit) = self::getPriceFinish($modelGetterGlobal, $totalProjectPrice);
            $coloredOptionPrice = self::getPriceFromPV($price['PRIX'], $pricePV, $unit);
            $price['PRIX'] += $coloredOptionPrice;
            return $price['PRIX'];
        } else {
            return (float)$price['PRIX'];
        }
    }

    /**
     * Prix du forfait pour coloration hors standard
     * @throws Exception
     */
    public static  function getPriceNonStandardColorPackage($modelGetterGlobal): int
    {
        $price = GCTools::getTarifByDatas('tarifsAccessoires', ['ref_accessoire' => 'plusValueForfaitHorsStandard'], $modelGetterGlobal);
        return (int)$price['PRIX'];
    }

    /**
     * Prix raccord d'angle de MC
     * @param $modelGetterGlobal
     * @param $typeFixation
     * @param $handrailHasPVColor
     * @param $handrailColorProperty
     * @return float
     * @throws Exception
     */
    public static  function getPriceHandRailCornerConnector($modelGetterGlobal, $typeFixation, $handrailHasPVColor, $handrailColorProperty): float
    {
        $price = GCTools::getTarifByDatas('tarifsFixationAngleMC', ['TYPE_FIXATION' => $typeFixation], $modelGetterGlobal);
        if ($handrailHasPVColor && !empty($handrailColorProperty)) {
            $coloredOptionPrice = DCGCTools::getPriceFromPV($price['PRIX'], $handrailColorProperty['pricePV'], $handrailColorProperty['unit']);
            $price['PRIX'] += $coloredOptionPrice;
            return $price['PRIX'];
        } else if ($handrailHasPVColor && !empty($handrailColorProperty)) {
            $coloredOptionPrice = DCGCTools::getPriceFromPV($price['PRIX'], $handrailColorProperty['pricePV'], $handrailColorProperty['unit']);
            $price['PRIX'] += $coloredOptionPrice;
        }
        return (float)$price['PRIX'];
    }

    /**
     * Prix support mural en pose tableau
     * @param $modelGetterGlobal
     * @return float
     * @throws Exception
     */
    public static  function getPriceWallBracketTablePose($modelGetterGlobal): float
    {
        $price = GCTools::getTarifByDatas(self::REF_TABLE_PRICING_WALL_BRACKET_TABLE_POSE, [], $modelGetterGlobal);
        return (float)$price['PRIX'];
    }

    /**
     * @param AFModelGetter $modelGetter
     * @return float
     * @throws Exception
     */
    public static  function getPriceTablePose(AFModelGetter $modelGetter): float
    {
        $price = GCTools::getTarifByDatas(self::REF_TABLE_PRICING_MODEL_TABLE_POSE, [], $modelGetter);
        return (float)$price['PRIX'];
    }

    /**
     * Prix de la finition
     * @param $modelGetterGlobal
     * @param $totalProjectPrice
     * @return array
     * @throws Exception
     */
    public static function getPriceFinish($modelGetterGlobal, $totalProjectPrice): array
    {
        $specificData = [];
        $specificData['PRIX_TOTAL_GC'] = $totalProjectPrice;

        $prix = GCTools::getTarifByDatas(self::REF_TABLE_FINISHES, $specificData, $modelGetterGlobal);
        return [$prix['PRIX'], $prix['UNIT']];
    }

    /**
     * Dual colouring Price
     * @param $modelGetterGlobal
     * @param $colorModule
     * @param $itemKey
     * @param $tableZoneFinition
     * @param $refTable
     * @param $totalProjectPrice
     * @return array
     * @throws Exception
     */
    public static  function getPriceBiColoration($modelGetterGlobal, $colorModule, $itemKey, $tableZoneFinition, $refTable, $totalProjectPrice): array
    {
        $specificData = [];
        $specificData['PRIX_TOTAL_GC'] = $totalProjectPrice;
        $specificData[$tableZoneFinition] = $colorModule->getFinition($itemKey);

        $price = GCTools::getTarifByDatas($refTable, $specificData, $modelGetterGlobal);
        return [$price['PRIX'], $price['UNIT']];
    }


    /**
     * Retourne le nombre de poteaux
     * @param $modelGetterGlobal
     * @return int
     */
    public static function getNumberOfPosts($modelGetterGlobal): int
    {
        $numberOfPosts = 0;
        $allSegmentsMC = GCTools::getAllSegmentsGC($modelGetterGlobal);
        foreach ($allSegmentsMC as $segmentMC) {
            /** @var GCContextInfoModule $moduleContextInfo */
            $modelGetterSegment = $segmentMC->getModelGetter();
            $editableSectionsMGTab = GCTools::getAllEditableSectionsMG($modelGetterSegment);
            $numberOfSections = count($editableSectionsMGTab);
            if (!empty($numberOfSections)) {
                $numberOfIntermediatePosts = ($numberOfSections - 1) * 2; // Double posts on intermediate
                $numberOfExtremityPosts = 2;
                $numberOfPosts += $numberOfIntermediatePosts + $numberOfExtremityPosts;
            }
        }
        return $numberOfPosts;
    }


    /************
     * GET ALL
     */

    /**
     * Compte le nombre total de croix de St-André
     * Voir pour la mettre dans getAllPersonalizedSections
     * @param $segment
     * @return int
     */
    public static function getAllStAndrewCrossBySegment($segment): int
    {
        $nbOfCross = 0;
        /** @var AFModelGetter $allSection */
        $allSection = GCTools::getAllEditableSectionsMG($segment->getModelGetter());
        foreach ($allSection as $section) {
            if ($section->moduleExists(DCGCCroixStAndreModule::NAME)) {
                //get module stAndrew's cross inside a section
                /** @var DCGCCroixStAndreModule $module * */
                $module = $section->getModule(DCGCCroixStAndreModule::NAME);
                //check if stAndrew's cross option is available on HMI
                $hasCrossOption = $module->getData(AFBasicModeleOptionModule::IS_PRESENT);
                //if option is present
                if ($hasCrossOption) {
                    //check if cross is selected
                    if ($module->getIsPresent()) {
                        $nbOfCross++;
                    }
                }
            }
        }
        return $nbOfCross;
    }

    /**
     * Retourne un tableau des finitions par section
     * @param $modelGetterGlobal
     * @param $arraySections
     * @param $totalProjectPrice
     * @return array
     * @throws Exception
     */
    public static function getAllSectionColored($modelGetterGlobal, $arraySections, $totalProjectPrice): array
    {
        $allFinishes = [];
        /** @var AFModelGetter $section * */
        foreach ($arraySections as $section) {
            $filling = '';
            //Check if dual-colouring module exists
            $colorModuleExists = $modelGetterGlobal->moduleExists(AFMulticolorationModule::NAME);
            //get type of filling inside section
            $hasMetalSheet = $section->getModule(GCToleModule::NAME)->getData(AFBasicModeleOptionModule::IS_PRESENT);
            $properties = AFModuleAdvanced::getDataInModule($section, GCModeleRSMModule::NAME, 'PropertiesRemplissage');
            $hasBars = $properties->getDataBool('useBarreaudage', false);
            if ($hasMetalSheet) {
                $filling = "tole";
            } elseif ($hasBars) {
                $filling = "remplissage-barreaux";
            }
            if ($colorModuleExists) {
                //Check if dual-color module is on section
                /* @var $colorModule AFMulticolorationModule */
                $colorModule = $section->getModule(AFMulticolorationModule::NAME);
                //if option is activated on IHM, get the finishing
                switch ($filling) {
                    case "tole":
                        $finish = $colorModule->getFinition(self::REF_ITEM_METAL_SHEET);
                        break;
                    case "remplissage-barreaux":
                        $finish = $colorModule->getFinition(self::REF_ITEM_BARS);
                        break;
                    default:
                        $finish = $colorModule->getFinition('finition');
                }
                list($pricePV, $unit) = self::getPriceBiColoration($modelGetterGlobal, $colorModule, $filling,
                    'FINITION',
                    self::REF_TABLE_FINISHES,
                    $totalProjectPrice);
                //Add filling if not existing yet
                $data_array = [];
                if (!is_null($finish)) {
                    if (!in_array($filling, $allFinishes)) {
                        $data_array['remplissage'] = $filling;
                    }
                    //Add description if not existing yet
                    if (!in_array($finish, $allFinishes)) {
                        $data_array['finition'] = $finish;
                    }
                    if (!in_array($pricePV, $allFinishes)) {
                        $data_array['PV'] = $pricePV;
                    }
                    if (!in_array($pricePV, $allFinishes)) {
                        $data_array['unit'] = $unit;
                    }
                    $allFinishes[] = $data_array;
                }
            }
        }
        return $allFinishes;
    }

    ////////////
    // STRINGS
    ////////////

    /**
     * @param AFModelGetter $modelGetterGlobal
     * @return string|null
     * @throws Exception
     */
    public static function getGamme(AFModelGetter $modelGetterGlobal): ?string
    {
        $tablesQueryAdvanced = TablesQueryAdvanced::getTablesQueryIntegration();
        if ($tablesQueryAdvanced->isTableExists('gamme')) {
            $data = $tablesQueryAdvanced->get($modelGetterGlobal, 'gamme')->getValues();
            if (!empty($data)) {
                // La table est en oneLine, donc il n'y aura toujours qu'un element
                return $data[0]['id'];
            }
        }
        return null;
    }


    ////////////
    // BOOLEANS
    ////////////

    /**
     * Permet de determiner si le projet contient un garde-corps rampant
     * @param $modelGetter
     * @return bool
     */
    public static function hasStairs($modelGetter): bool
    {
        $allSegmentsMC = GCTools::getAllSegmentsGC($modelGetter);

        //count subdivision
        foreach ($allSegmentsMC as $segmentMC) {

            /** @var GCContextInfoModule $moduleContextInfo */
            $modelGetterSegment = $segmentMC->getModelGetter();
            $moduleContextInfo = $modelGetterSegment->getModule(GCContextInfoModule::NAME);

            $masonryItem = $moduleContextInfo->getLinkedMaconnerieContentItem($modelGetter);
            $isStair = $masonryItem instanceof GCMaconnerieContentItemGCRampant;
            if ($isStair) {
                return true;
            }
        }

        return false;
    }

    /**
     * Détecte la pose tableau
     * @param $modelGetterSegment
     * @return bool
     */
    public static function isTablePose($modelGetterSegment): bool
    {
        $isPoseTableau = false;
        if ($modelGetterSegment->moduleExists(GCPoseTableauModule::NAME)) {
            if ($modelGetterSegment->getData('module:' . GCPoseTableauModule::NAME . '_' . GCPoseTableauModule::TYPE) === 'with') {
                $isPoseTableau = true;
            }
        }
        return $isPoseTableau;
    }


    ////////////
    // INTEGERS / FLOATS
    ////////////


    /**
     * @param $totalPrice
     * @param $pv
     * @param $unit
     * @return float
     */
    public static function getPriceFromPV(&$totalPrice, $pv, $unit): float
    {
        switch ($unit) {
            case '%': $optionPrice = $totalPrice * ($pv / 100);
                return $optionPrice;
            default : $optionPrice = $pv;
                return $optionPrice;
        }
    }

    /**
     * Retourne la longueur en diagonale de l'escalier
     * @param $modelGetter
     * @return float
     */
    public static function getLengthStairs($modelGetter): float
    {
        $arrayLengthStairs = [];
        $allSegments = GCTools::getAllSegmentsGC($modelGetter);

        foreach ($allSegments as $segment) {

            /** @var GCContextInfoModule $moduleContextInfo * */
            $modelGetterSegment = $segment->getModelGetter();
            $moduleContextInfo = $modelGetterSegment->getModule(GCContextInfoModule::NAME);
            $masonryItem = $moduleContextInfo->getLinkedMaconnerieContentItem($modelGetter);
            $isStair = $masonryItem instanceof GCMaconnerieContentItemGCRampant;
            // Get stair masonry length
            if($isStair) {
                $lengthMacSegment = $masonryItem->getLength();
                $formattedLength = TarifGCTools::convertToMeters($lengthMacSegment);
                $arrayLengthStairs[] = $formattedLength;
            }
        }
        return array_sum($arrayLengthStairs);
    }

    /**
     * @param $modelGetter
     * @return float
     */
    public static function getLengthStraight($modelGetter): float
    {
        $arrayLengthStraight = [];
        $allSegments = GCTools::getAllSegmentsGC($modelGetter);

        foreach ($allSegments as $segment) {

            /** @var GCContextInfoModule $moduleContextInfo * */
            $modelGetterSegment = $segment->getModelGetter();
            $moduleContextInfo = $modelGetterSegment->getModule(GCContextInfoModule::NAME);
            $masonryItem = $moduleContextInfo->getLinkedMaconnerieContentItem($modelGetter);
            $isStair = $masonryItem instanceof GCMaconnerieContentItemGCRampant;
            // Get straight masonry length
            if(!$isStair) {
                $lengthMacSegment = $masonryItem->getLength();
                $formattedLength = TarifGCTools::convertToMeters($lengthMacSegment);
                $arrayLengthStraight[] = $formattedLength;
            }
        }
        return array_sum($arrayLengthStraight);
    }

    /**
     * @param $price
     * @param $pv
     * @param $unite
     * @return float
     */
    public static function applyPVToPrice(&$price, $pv, $unite): float
    {
        switch ($unite) {
            case '%':
                return $price += ($price * ($pv / 100));
            default :
                return $price += $pv;
        }
    }

    /**
     * Tarification des poteaux
     * @param $modelGetterSegment
     * @param $modelGetterGlobal
     * @return float
     * @throws Exception
     */
    public static function computePostsPriceBySegment($modelGetterSegment, $modelGetterGlobal): float
    {
        $totalPostsPrice = 0;
        //Get number of posts per type
        $dataPosts = self::computeDataJunctionBySegment($modelGetterGlobal, $modelGetterSegment);
        foreach ($dataPosts as $key => $post) {
            $price = 0;
            switch ($key) {
                case self::TYPE_POSE_STAIRS:
                    list($pvRampant, $uniteRampant) = DCGCTools::getDatasPrixOption($modelGetterGlobal,
                        "PV_RAMPANT");
                    break;
                case self::TYPE_POSE_STRAIGHT:
                default:
                    $pvRampant = 0;
                    break;
            }
            if ($post['nbPoteauxInter'] || $post['nbPUJ'] || $post['nbPDA'] || $post['nbPPT']) {
                if ($post['nbPoteauxInter']) {
                    $price = self::getPriceIntermediatePost($modelGetterGlobal);
                    if (isset($pvRampant) && $pvRampant) {
                        GCTools::applyPVToPrice($price, $pvRampant, $uniteRampant);
                    }
                    $totalPostsPrice += $price * $post['nbPoteauxInter'];
                }
                if ($post['nbPUJ']) {
                    $price = self::getPricePostAngle($modelGetterGlobal);
                    $price = $price / 2;         // Added on VT864 for pricing by section (one post per segment)
                    if (isset($pvRampant) && $pvRampant) {
                        GCTools::applyPVToPrice($price, $pvRampant, $uniteRampant);
                    }
                    $totalPostsPrice += $price * $post['nbPUJ'];
                }
                if ($post['nbPDA']) {
                    $price = self::getPriceEndPost($modelGetterGlobal);
                    if (isset($pvRampant) && $pvRampant) {
                        GCTools::applyPVToPrice($price, $pvRampant, $uniteRampant);
                    }
                    $totalPostsPrice += $price * $post['nbPDA'];
                }
                if ($post['nbPPT']) {
                    $price = self::getPriceWallBracketTablePose($modelGetterGlobal);
                    $totalPostsPrice += $price * $post['nbPPT'];
                }
            }
        }
        return $totalPostsPrice;
    }

    /**
     * Calcul le nombre de chevilles par poteau
     * @throws Exception
     */
    public static function computeNumberOfWallScrews($modelGetterGlobal): int
    {
        $nbOfWallScrews = 0;
        $dataPostsKit = DCGCTools::computeGlobalDataJunction($modelGetterGlobal);
        if(!empty($dataPostsKit)) {
            $nbOfWallScrews = $dataPostsKit['typePoseDroit']['nbPPT'] * 2 ;
        }
        return $nbOfWallScrews;
    }

    /**
     * @param $modelGetterGlobal
     * @return int
     * @throws Exception
     */
    public static function computeNumberOfFixationScrews($modelGetterGlobal): int
    {
        $nbOfPosts = DCGCTools::getNumberOfPosts($modelGetterGlobal);
        $nbOfScrews = $nbOfPosts;
        $dataPostsKit = DCGCTools::computeGlobalDataJunction($modelGetterGlobal);
        if(!empty($dataPostsKit)) {
            if($dataPostsKit['typePoseDroit']['nbPPT'] > 0) {
                $nbOfScrews = $nbOfPosts - $dataPostsKit['typePoseDroit']['nbPPT'] * 2 ;
            }
        }
        return $nbOfScrews;
    }

    ////////////
    // ARRAYS
    ////////////

    /**
     * @param AFModelGetter $modelGetter
     * @param $refItem
     * @param array|null $specificDatas
     * @return array
     * @throws \Exception
     */
    public static function getDatasPrixOption(AFModelGetter $modelGetter, $refItem, array $specificDatas = null): array
    {
        $unite = null;
        if ($specificDatas === null) {
            $specificDatas = [];
        }

        $specificDatas['REF_ITEM_OPTION'] = $refItem;

        $qtyDefault = 0;
        $datasTarif = self::getTarifByDatas('tarifsOptions', $specificDatas, $modelGetter);
        if (isset($datasTarif['DEFAULT']) && !empty($datasTarif['DEFAULT'])) {
            $qtyDefault = $datasTarif['DEFAULT'] != 'NULL' ? $datasTarif['DEFAULT'] != 'NULL' : 0;
        }
        $prix = $datasTarif['PRIX'];
        $unite = $datasTarif['UNIT'] == 'NULL' ? '' : $datasTarif['UNIT'];

        return [$prix, $unite, $qtyDefault];
    }

    /**
     * @param AFModelGetter $modelGetter
     * @return int[]
     * @throws Exception
     */
    public static function getNumberOfAnglesByType(AFModelGetter $modelGetter): array
    {
        $jointuresByType = [
            self::REF_ANGLE_STRAIGHT_VARIABLE => 0,
            self::REF_ANGLE_VERTICAL_STRAIGHT => 0,
            self::REF_ANGLE_VERTICAL_VARIABLE => 0
        ];

        /** @var GCGlobalJointures $moduleGlobalJointure */
        $moduleGlobalJointure = $modelGetter->getModule(GCGlobalJointures::NAME);

        $jointures = $moduleGlobalJointure->jointureDescriptors;
        foreach ($jointures as $jointureDescriptor) {

            $linkedMCItems = $jointureDescriptor->getLinkedMaconnerieItems($modelGetter);
            $numLinked = count($linkedMCItems);
            if ($numLinked === 2) {

                $keys = array_keys($linkedMCItems);
                $itemA = $linkedMCItems[$keys[0]];
                $itemB = $linkedMCItems[$keys[1]];

                if ($itemA instanceof AbstractGCMaconnerieContentItemGC && $itemB instanceof AbstractGCMaconnerieContentItemGC) {

                    $isRailingTerraceA = $itemA instanceof GCMaconnerieContentItemGCDroit;
                    $isRailingTerraceB = $itemB instanceof GCMaconnerieContentItemGCDroit;

                    // Si les deux garde-corps sont des garde-corps droits
                    if ($isRailingTerraceA && $isRailingTerraceB) {

                        // Tous les angles sauf 180 (pas de piece)
                        $segment3DItemA = $itemA->getSegment3D();
                        $segment3DItemB = $itemB->getSegment3D();

                        $jointuresByType[self::REF_ANGLE_STRAIGHT_VARIABLE]++;
                    } // Si au moins un garde-corps est un garde-corps droit et l'autre rampant
                    elseif (!(!$isRailingTerraceA && !$isRailingTerraceB)) {

                        // Uniquement dans le cas d'un angle à 180°
                        $segment3DItemA = $itemA->getSegment3D();
                        $segment3DItemB = $itemB->getSegment3D();

                        $angle = GCJointureTools::computeAngleZBetweenSegments($segment3DItemA, $segment3DItemB);
                        if (\MathBT::cmpFloat(deg2rad($angle), M_PI, 0.5)) {
                            $jointuresByType[self::REF_ANGLE_VERTICAL_STRAIGHT]++;
                        } else {
                            $jointuresByType[self::REF_ANGLE_VERTICAL_VARIABLE]++;
                        }
                    }
                }
            }
        }

        return $jointuresByType;
    }

    /**
     * Retourne un tableau des jointures du GC
     * @param $modelGetterGlobal
     * @return array
     * @throws Exception
     */
    public static function computeGlobalDataJunction($modelGetterGlobal): array
    {
        $allSegments = GCTools::getAllSegmentsGC($modelGetterGlobal);
        $posts = [];
        foreach ($allSegments as $segment) {
            $modelGetterSegment = $segment->getModelGetter();
            $moduleContextInfo = $modelGetterSegment->getModule(GCContextInfoModule::NAME);
            $masonryItem = $moduleContextInfo->getLinkedMaconnerieContentItem($modelGetterGlobal);
            if ($masonryItem instanceof AbstractGCMaconnerieContentItemGC) {
                $isStair = $masonryItem instanceof GCMaconnerieContentItemGCRampant;
                $typeSegment = $isStair ? self::TYPE_POSE_STAIRS : self::TYPE_POSE_STRAIGHT;
                $editableSectionsMGTab = GCTools::getAllEditableSectionsMG($modelGetterSegment);
                $nbSections = count($editableSectionsMGTab);
                $typeDepart = $modelGetterSegment->getModule(GCSegmentExtremumInfo::NAME)->getTypeArriveeBegin();
                $typeArrive = $modelGetterSegment->getModule(GCSegmentExtremumInfo::NAME)->getTypeArriveeEnd();
                if ($nbSections > 0) {
                    $posts[$typeSegment]['nbPoteauxInter'] += (int)$nbSections - 1;
                }
                if ($typeDepart === GCJointureDescriptor::TYPE_JOINTURE_PIE) {
                    $posts[$typeSegment]['nbPDA']++;
                }
                if ($typeArrive === GCJointureDescriptor::TYPE_JOINTURE_PIE) {
                    $posts[$typeSegment]['nbPDA']++;
                }
                if ($typeDepart === GCJointureDescriptor::TYPE_JOINTURE_PPT) {
                    $posts[$typeSegment]['nbPPT']++;
                }
                if ($typeArrive === GCJointureDescriptor::TYPE_JOINTURE_PPT) {
                    $posts[$typeSegment]['nbPPT']++;
                }
            }
        }
        $numberAngleTarifaire = DCGCTools::getNumberAnglesTarifaires($modelGetterGlobal);
        $nbAngles = $numberAngleTarifaire[DCGCTools::REF_ANGLE_DROIT] + $numberAngleTarifaire[DCGCTools::REF_ANGLE_VARIABLE];
        foreach ($posts as $typeSegment => &$post) {
            if ($typeSegment == self::TYPE_POSE_STRAIGHT) {
                $posts[$typeSegment]['nbPDA'] -= $posts[$typeSegment]['nbPPT'];
                $posts[$typeSegment]['nbPUJ'] = $nbAngles;
                $posts[$typeSegment]['nbPDA'] -= $nbAngles * 2;
            }
        }
        return $posts;
    }

    /**
     * Retourne un tableau du nombre d'angles tarifaires dans le projet
     * @param AFModelGetter $modelGetter
     * @return array
     * @throws Exception
     */
    public static function getNumberAnglesTarifaires(AFModelGetter $modelGetter): array
    {
        $jointuresByType = [self::REF_ANGLE_DROIT => 0, self::REF_ANGLE_VARIABLE => 0, self::REF_ANGLE_VERTICAL => 0];

        /** @var GCGlobalJointures $moduleGlobalJointure */
        $moduleGlobalJointure = $modelGetter->getModule(GCGlobalJointures::NAME);

        $jointures = $moduleGlobalJointure->jointureDescriptors;
        foreach ($jointures as $jointureDescriptor) {

            $linkedMCItems = $jointureDescriptor->getLinkedMaconnerieItems($modelGetter);
            $numLinked = count($linkedMCItems);
            if ($numLinked === 2) {

                $keys = array_keys($linkedMCItems);
                $itemA = $linkedMCItems[$keys[0]];
                $itemB = $linkedMCItems[$keys[1]];

                if ($itemA instanceof AbstractGCMaconnerieContentItemGC && $itemB instanceof AbstractGCMaconnerieContentItemGC) {

                    $isRailingTerraceA = $itemA instanceof GCMaconnerieContentItemGCDroit;
                    $isRailingTerraceB = $itemB instanceof GCMaconnerieContentItemGCDroit;

                    // Si les deux garde-corps sont des garde-corps droits
                    if ($isRailingTerraceA && $isRailingTerraceB) {

                        // Tous les angles sauf 180 (pas de piece)
                        $segment3DItemA = $itemA->getSegment3D();
                        $segment3DItemB = $itemB->getSegment3D();

                        $angle = GCJointureTools::computeAngleZBetweenSegments($segment3DItemA, $segment3DItemB);
                        if (round($angle) == 90 || round($angle) == 270) {
                            $jointuresByType[self::REF_ANGLE_DROIT]++;
                        } elseif (!(round($angle) == 180)) {
                            $jointuresByType[self::REF_ANGLE_VARIABLE]++;
                        }

                    }
                    // Si au moins un garde-corps est un garde-corps droit et l'autre rampant
                    elseif (!(!$isRailingTerraceA && !$isRailingTerraceB)) {

                        // Uniquement dans le cas d'un angle � 180�
                        $segment3DItemA = $itemA->getSegment3D();
                        $segment3DItemB = $itemB->getSegment3D();

                        $angle = GCJointureTools::computeAngleZBetweenSegments($segment3DItemA, $segment3DItemB);
                        if (\MathBT::cmpFloat(deg2rad($angle), M_PI, 0.5)) {
                            $jointuresByType[self::REF_ANGLE_VERTICAL]++;
                        }
                    }
                }
            }
        }

        return $jointuresByType;
    }

    /**
     * Retourne un tableau des jointures du GC
     * @param $modelGetterGlobal
     * @param $modelGetterSegment
     * @return array
     * @throws Exception
     */
    public static function computeDataJunctionBySegment($modelGetterGlobal, $modelGetterSegment): array
    {
        $posts = [];

        $moduleContextInfo = $modelGetterSegment->getModule(GCContextInfoModule::NAME);
        $masonryItem = $moduleContextInfo->getLinkedMaconnerieContentItem($modelGetterGlobal);
        if ($masonryItem instanceof AbstractGCMaconnerieContentItemGC) {
            $isStair = $masonryItem instanceof GCMaconnerieContentItemGCRampant;
            $typeSegment = $isStair ? self::TYPE_POSE_STAIRS : self::TYPE_POSE_STRAIGHT;
            $editableSectionsMGTab = GCTools::getAllEditableSectionsMG($modelGetterSegment);
            $nbSections = count($editableSectionsMGTab);
            $typeDepart = $modelGetterSegment->getModule(GCSegmentExtremumInfo::NAME)->getTypeArriveeBegin();
            $typeArrive = $modelGetterSegment->getModule(GCSegmentExtremumInfo::NAME)->getTypeArriveeEnd();
            if ($nbSections > 0) {
                $posts[$typeSegment]['nbPoteauxInter'] += (int)$nbSections - 1;
            }
            if ($typeDepart === GCJointureDescriptor::TYPE_JOINTURE_PIE) {
                $posts[$typeSegment]['nbPDA']++;
            }
            if ($typeArrive === GCJointureDescriptor::TYPE_JOINTURE_PIE) {
                $posts[$typeSegment]['nbPDA']++;
            }
            if ($typeDepart === GCJointureDescriptor::TYPE_JOINTURE_PPT) {
                $posts[$typeSegment]['nbPPT']++;
            }
            if ($typeArrive === GCJointureDescriptor::TYPE_JOINTURE_PPT) {
                $posts[$typeSegment]['nbPPT']++;
            }
        }

        $numberAngleTarifaire = DCGCTools::getNumberAnglesTarifaires($modelGetterGlobal);
        $nbAngles = $numberAngleTarifaire[DCGCTools::REF_ANGLE_DROIT] + $numberAngleTarifaire[DCGCTools::REF_ANGLE_VARIABLE];
        foreach ($posts as $typeSegment => &$post) {
            if ($typeSegment == self::TYPE_POSE_STRAIGHT) {
                $posts[$typeSegment]['nbPDA'] -= $posts[$typeSegment]['nbPPT'];
                $posts[$typeSegment]['nbPUJ'] = $nbAngles;
                $posts[$typeSegment]['nbPDA'] -= $nbAngles;
            }
        }
        return $posts;
    }

    /**
     * @param $refTable
     * @param $specificDatas
     * @param $modelGetter
     * @return int|mixed
     * @throws \Exception
     */
    public static function getTarifByDatas($refTable, $specificDatas, $modelGetter)
    {
        $table = TablesQueryAdvanced::getTablesQueryTarification();
        if ($table->isTableExists($refTable)) {
            $price = $table->get($modelGetter,
                $refTable,
                $specificDatas);
        }

        return $price;
    }

}