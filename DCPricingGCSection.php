<?php

namespace batigardecorps2018\fournisseurs\dc\tarif;

use AT\BDD\bddAdvanced\BDDAdvanced;
use batiamenagementmulti\facade\engineV2\modules\base\simpleOptions\FacSimpleOption;
use batigardecorps2014\tools\TarifGCTools;
use batigardecorps2018\core\modules\contexteInfo\GCContextInfoModule;
use batigardecorps2018\core\modules\globalProperties\poseGlobale\AbstractGCTypePoseGlobaleModule;
use batigardecorps2018\core\modules\globalProperties\poseGlobale\GCTypePoseGlobaleMonoModule;
use batigardecorps2018\core\modules\maconnerie\contentItems\GCMaconnerieContentItemGCRampant;
use batigardecorps2018\core\modules\properties\customHeightAboveMC\GCCustomHeightAboveMC;
use batigardecorps2018\core\modules\properties\mainCourante\GCMainCouranteModule;
use batigardecorps2018\core\modules\properties\modeles\modeleRSM\GCModeleRSMModule;
use batigardecorps2018\core\modules\properties\tole\GCToleModule;
use batigardecorps2018\core\modules\properties\vitrage\GCVitrageModule;
use batigardecorps2018\core\tools\GCTools;
use batigardecorps2018\fournisseurs\dc\modules\typeJonctionMC\DCGCTypeJonctionMCModule;
use batigardecorps2018\fournisseurs\dc\modules\vitrageProperties\DCGCVitragePropertiesModule;
use batigardecorps2018\fournisseurs\dc\tools\DCGCTools;
use com\framework\genericModules\AFBasicModeleOptionModule;
use com\framework\product\AFModelGetter;
use com\framework\tarification\AFAbstractTarificationProduct;
use com\framework\tarification\descriptif\AFAbstractDescriptifTarif;
use com\framework\tarification\descriptif\AFDescriptifTarifCategorie;
use com\framework\tarification\descriptif\AFDescriptifTarifItem;
use com\framework\wrapper\AFModuleAdvanced;
use com\framework\wrapper\finitionMF\AFModuleFinitionMF;
use com\framework\wrapper\multiColorationModule\AFMulticolorationModule;
use com\translation\Translatable;
use com\translation\Translation;
use Exception;

class DCPricingGCSection extends AFAbstractTarificationProduct
{

    //////////////
    // CONSTANTS
    //////////////

    const STAIRS = 'typePoseRampant';
    const STRAIGHT = 'typePoseDroit';
    const GLOBAL = 'typePoseGlobale';

    const REF_TABLE_PRICING_HANDRAIL_FINISHES = 'tarifsFinitionMC';

    const REF_ITEM_HANDRAIL = 'main-courante';
    const REF_ITEM_METAL_SHEET = 'tole';
    const REF_ITEM_BARS = 'remplissage-barreaux';
    const GS_COLUMN_HANDRAIL_FINISH = 'FINITION_MAIN_COURANTE';

    // Type of handrail attachment
    const REF_ANGLE_STRAIGHT_VARIABLE = 'angleDroitVariable';
    const REF_ANGLE_VERTICAL_STRAIGHT = 'angleVerticalDroit';
    const REF_ANGLE_VERTICAL_VARIABLE = 'angleVerticalVariable';

    protected $translation;
    /* integers | floats */
    private $totalProjectPrice;
    private $totalSegmentPrice;
    private $sectionPrice;
    private $highestPV;
    /* arrays */
    private $arrayNonStandardColor;
    private $arrayMessage;
    private $railingColorProperty;
    private $handrailColorProperty;
    /* booleans */
    private $railingHasPVColor;
    private $handrailHasPVColor;
    /* others */
    private $pose;


    ////////////
    // METHODS
    ////////////

    /**
     * Returns an AFAbstractDescriptifTarif detailing pricing for the product
     * @param mixed $datas : additional info for the pricing
     * @return AFAbstractDescriptifTarif
     * @throws Exception
     */
    public function getDetailsTarification($datas = null): AFAbstractDescriptifTarif
    {
        $this->railingColorProperty = [];
        $this->handrailColorProperty = [];
        $this->arrayNonStandardColor = [];
        $this->highestPV = 0;
        $this->arrayMessage = [];
        $this->pose = $this->modelGetter->getModule(AbstractGCTypePoseGlobaleModule::NAME)
            ->getData(GCTypePoseGlobaleMonoModule::TYPE_POSE_GLOBALE);

        $categoryMaster = $this->getCategoryMaster();

        // Tarification de chaque segment et section
        $this->genDetailsSegment($categoryMaster);

        // Tarification des éléments globaux à tout le projet
        $this->genDetailsGlobal($categoryMaster);

        return $categoryMaster;
    }

    /**
     * @param AFDescriptifTarifCategorie $categoryMaster
     * @throws Exception
     */
    public function genDetailsGlobal(AFDescriptifTarifCategorie $categoryMaster): void
    {

        $title = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
            'GLOBAL');
        $categoryGlobal = AFDescriptifTarifCategorie::createCategorie($title);

        $libGamme = [];
        $titleGamme = null;
        $gamme = DCGCTools::getGamme($this->modelGetter);
        if ($gamme) {
            $titleGamme = BDDAdvanced::getBDDItemByRoute($gamme)->getItemListLib($this->translation);
            $libGamme[] = Translatable::addSuffix('Gamme', ' : ');
            $libGamme[] = $titleGamme;
            $libGamme = Translatable::create($libGamme);
        }
        $categoryGlobal->addChild(AFDescriptifTarifItem::createItemSimpleDescription($libGamme));

        //Main-Courante
        $this->genDetailsHandrailModel($categoryGlobal);

        //Poteaux
        $this->genDetailsGlobalPosts($categoryGlobal);

        //Pieces angles main-courantes
        $this->genDetailsHandrailsConnectors($categoryGlobal);

        //Tarification des options des fixations poteaux
        $this->genDetailsModelFixationPost($categoryGlobal);

        //Tarification des options simples
        $this->genDetailsSimpleOptions($categoryGlobal);

        //Tarification de la coloration sur éléments globaux (MC, GC)
        $this->genDetailsGlobalFinishes($categoryGlobal);

        // Affichage du message d'alerte lors du choix du teinte bois ou hors standard
        if (!empty($this->arrayMessage)) $categoryGlobal->addChild(AFDescriptifTarifItem::createItemSimpleDescription($this->arrayMessage[0]));

        // Affichage du forfait sur coloration hors-standard
        if (!empty($this->arrayNonStandardColor)) $this->genDetailsNonStandardColorPackage($categoryGlobal);

        $categoryMaster->addChild($categoryGlobal);
    }

    /**
     * Titre de catégorie
     * @return AFDescriptifTarifCategorie
     * @throws Exception
     */
    protected function getCategoryMaster(): AFDescriptifTarifCategorie
    {
        $title = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'TITLE_MASTER_TARIF');
        return AFDescriptifTarifCategorie::createCategorie($title);
    }

    /**
     * Tarification de chaque segment
     * @param AFDescriptifTarifCategorie $categoryProduct
     * @throws Exception
     */
    protected function genDetailsSegment(AFDescriptifTarifCategorie $categoryProduct): void
    {
        $this->sectionPrice = 0;
        $this->totalProjectPrice = 0;
        $arraySegmentsPrices = [];
        $this->translation = $this->modelGetter->getTranslation();
        $allSegments = GCTools::getAllSegmentsGC($this->modelGetter);
        foreach ($allSegments as $segment) {
            $this->totalSegmentPrice = 0;
            $this->highestPV = 0;
            $totalPostsPrice = 0;
            /** @var GCContextInfoModule $moduleContextInfo **/
            $modelGetterSegment = $segment->getModelGetter();
            $moduleContextInfo = $modelGetterSegment->getModule(GCContextInfoModule::NAME);
            // Retrieve number of sections by segment
            $editableSectionsMGTab = GCTools::getAllEditableSectionsMG($modelGetterSegment);
            $nbSections = count($editableSectionsMGTab);
            // We check if we are on stair
            $masonryItem = $moduleContextInfo->getLinkedMaconnerieContentItem($this->modelGetter); // $segment ???
            $isStair = $masonryItem instanceof GCMaconnerieContentItemGCRampant;
            // Get masonry length
            $lengthMacSegment = round($masonryItem->getLength(), 2 , PHP_ROUND_HALF_UP);
            $formattedLength = round(TarifGCTools::convertToMeters($lengthMacSegment), 2, PHP_ROUND_HALF_UP);
            // Create label for description railing
            if ($formattedLength) {
                $titleProduct = $this->getTitleProduct($isStair, $formattedLength, $modelGetterSegment);
                $categorySegment = AFDescriptifTarifCategorie::createCategorie($titleProduct);
                $categoryProduct->addChild($categorySegment);
                // ----------------------------------- Tarification des poteaux
                $totalPostsPrice = DCGCTools::computePostsPriceBySegment($modelGetterSegment, $this->modelGetter);
                //Affichage des types de modèles par sections
                foreach ($editableSectionsMGTab as $modelGetterSection) {
                    $lengthSectionTarifaire = $formattedLength / $nbSections;
                    // -------------------------------- Crée un tableau de prix de section et ajoute le modèle de section dans le devis
                    $itemPricingSection = $this->createItemTarifSection($modelGetterSection,
                        $isStair,
                        $lengthSectionTarifaire
                    );
                    $categorySegment->addChild($itemPricingSection);
                    // We check the type of the filling and display it
                    $hasMetalSheetOnSection = $modelGetterSection->getModule(GCToleModule::NAME)->getData(AFBasicModeleOptionModule::IS_PRESENT);
                    $properties = AFModuleAdvanced::getDataInModule($modelGetterSection, GCModeleRSMModule::NAME, 'PropertiesRemplissage');
                    $hasBarsOnSection = $properties->getDataBool('useBarreaudage', false);
                    $hasGlassOnSection = $modelGetterSection->getModule(GCVitrageModule::NAME)->getData(AFBasicModeleOptionModule::IS_PRESENT);
                    if ($hasMetalSheetOnSection || $hasBarsOnSection) {
                        $this->genDetailsMetalSheetOrBarsBySection($categorySegment, $modelGetterSection, $this->sectionPrice, $isStair, $hasMetalSheetOnSection, $hasBarsOnSection);
                    } else if ($hasGlassOnSection) {
                        $this->genDetailsGlassBySection($categorySegment, $modelGetterSection, $this->sectionPrice, $lengthMacSegment, $isStair, $nbSections);
                    }
                }
                $price = $itemPricingSection->getPrixUnitaire();
                $quantity = $itemPricingSection->getQuantite();
                // -------------------------------- Calcul du prix d'un segment
                $this->totalSegmentPrice = round(($price * $quantity) * $nbSections + $totalPostsPrice, 2, PHP_ROUND_HALF_UP);
                $arraySegmentsPrices[] = $this->totalSegmentPrice;
                $this->setRailingColorData();
                $this->setHandrailColorData();
            }
            // -------------------------------- Tarification des options des finitions
            $this->genDetailsFinishesBySegment($categorySegment, $modelGetterSegment);
            // -------------------------------- Tarification des croix de St-André
            $this->genDetailsStAndrewCross($categorySegment, $segment, $isStair);
        }
        // -------------------------------- Calcul du prix du projet
        $this->totalProjectPrice = (array_sum($arraySegmentsPrices));
    }

    /**
     * @param AFDescriptifTarifCategorie $categoryMaster
     * @throws Exception
     */
    protected function genDetailsGlobalPosts(AFDescriptifTarifCategorie $categoryMaster)
    {
        $dataPosts = DCGCTools::computeGlobalDataJunction($this->modelGetter);
        foreach ($dataPosts as $key => $post) {
            $price = 0;
            switch ($key) {
                case self::STAIRS:
                    list($pvRampant, $uniteRampant) = GCTools::getDatasPrixOption($this->modelGetter,
                        "PV_RAMPANT");
                    break;
                case self::STRAIGHT:
                default:
                    $pvRampant = 0;
                    break;
            }
            if ($post['nbPoteauxInter']) {
                $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'POSTS_INTER_DESC_STRAIGHT');
                $price = DCGCTools::getPriceIntermediatePost($this->modelGetter);
                if (isset($pvRampant) && $pvRampant) {
                    $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'POSTS_INTER_DESC_STAIRS');
                    GCTools::applyPVToPrice($price, $pvRampant, $uniteRampant);
                }
                $itemTarif = AFDescriptifTarifItem::createItem($label, $price, $post['nbPoteauxInter'], '');
                $categoryMaster->addChild($itemTarif);
            }
            if ($post['nbPUJ']) {
                $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'POSTS_ANGLE_DESC_STRAIGHT');
                $price = DCGCTools::getPricePostAngle($this->modelGetter);
                if (isset($pvRampant) && $pvRampant) {
                    $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'POSTS_ANGLE_DESC_STAIRS');
                    GCTools::applyPVToPrice($price, $pvRampant, $uniteRampant);
                }
                $itemTarif = AFDescriptifTarifItem::createItem($label, $price, $post['nbPUJ'], '');
                $categoryMaster->addChild($itemTarif);
            }
            if ($post['nbPDA']) {
                $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'POSTS_EXT_DESC_STRAIGHT');
                $price = DCGCTools::getPriceEndPost($this->modelGetter);
                if (isset($pvRampant) && $pvRampant) {
                    $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'POSTS_EXT_DESC_STAIRS');
                    GCTools::applyPVToPrice($price, $pvRampant, $uniteRampant);
                }
                if($post['nbPDA'] > 0) {
                    $itemTarif = AFDescriptifTarifItem::createItem($label, $price, $post['nbPDA'], '');
                    $categoryMaster->addChild($itemTarif);
                }
            }
            if ($post['nbPPT']) {
                $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'WALL_FIXATION');
                $price = DCGCTools::getPriceWallBracketTablePose($this->modelGetter);
                $itemTarif = AFDescriptifTarifItem::createItem($label, $price, $post['nbPPT'], '');
                $categoryMaster->addChild($itemTarif);
            }
        }
    }

    /**
     * Tarification du modèle de main-courante
     * @param AFDescriptifTarifCategorie $categoryGlobal
     * @return void
     * @throws Exception
     */
    protected function genDetailsHandrailModel(AFDescriptifTarifCategorie $categoryGlobal): void
    {
        $modelMC = AFModuleAdvanced::getDataInModule($this->modelGetter,
            GCMainCouranteModule::NAME,
            GCMainCouranteModule::MODELE);
        $itemModel = BDDAdvanced::getBDDItemByRoute($modelMC);
        $lib = [];
        $lib[] = Translatable::addSuffix($this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'MAIN_COURANTE'), " : ");
        $lib[] = $itemModel->getItemListLib($this->translation);
        $lib = Translatable::create($lib);
        $itemTarif = AFDescriptifTarifItem::createItemSimpleDescription($lib);
        $categoryGlobal->addChild($itemTarif);
    }

    /**
     * Tarification pour les raccords d'angle
     * @param AFDescriptifTarifCategorie $categoryGlobal
     * @return void
     * @throws Exception
     */
    protected function genDetailsHandrailsConnectors(AFDescriptifTarifCategorie $categoryGlobal): void
    {
        $hasConnector = $this->modelGetter->moduleExists(DCGCTypeJonctionMCModule::NAME);
        $connectorType = AFmoduleAdvanced::getDataInModule($this->modelGetter, DCGCTypeJonctionMCModule::NAME, DCGCTypeJonctionMCModule::TYPE);
        [, , $type] = explode('.', $connectorType);
        if ($hasConnector && $type === 'PIECE_RACCORD') {
            $numberAngles = DCGCTools::getNumberOfAnglesByType($this->modelGetter);
            $totalNumberOfAngles = array_sum($numberAngles);
            if (empty($totalNumberOfAngles)) {
                $totalNumberOfAngles = 0;
            }
            $handrailModel = AFModuleAdvanced::getDataInModule($this->modelGetter,
                GCMainCouranteModule::NAME,
                GCMainCouranteModule::MODELE);
            $isCircleHandrail = strpos($handrailModel, 'ROND') !== false;

            if ($isCircleHandrail) {
                // Jonction angle variable main-courante + Jonction rampant main-courante + Jonction angle variable + rampant
                $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'JONCTION_RONDE');
                $quantity = $totalNumberOfAngles;
                $price = DCGCTools::getPriceHandRailCornerConnector($this->modelGetter, '', $this->handrailHasPVColor, $this->handrailColorProperty);
                if (!empty($quantity) && !empty($price)) {
                    $categoryGlobal->addChild(AFDescriptifTarifItem::createItem($label, $price, $quantity));
                }
            } else {
                // Junction on straight handrails
                $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'JONCTION_DROIT_RECT');
                $quantity = $numberAngles[self::REF_ANGLE_STRAIGHT_VARIABLE];
                $price = DCGCTools::getPriceHandRailCornerConnector($this->modelGetter, self::REF_ANGLE_STRAIGHT_VARIABLE,  $this->handrailHasPVColor, $this->handrailColorProperty);
                if (!empty($quantity) && !empty($price)) {
                    $categoryGlobal->addChild(AFDescriptifTarifItem::createItem($label, $price, $quantity));
                }
                // Junction straight hr -> stairs hr
                $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'JONCTION_DROIT_RAMPANT_RECT');
                $quantity = $numberAngles[self::REF_ANGLE_VERTICAL_VARIABLE];
                $price = DCGCTools::getPriceHandRailCornerConnector($this->modelGetter, self::REF_ANGLE_VERTICAL_VARIABLE,  $this->handrailHasPVColor, $this->handrailColorProperty);
                if (!empty($quantity) && !empty($price)) {
                    $categoryGlobal->addChild(AFDescriptifTarifItem::createItem($label, $price, $quantity));
                }
                // Junction stairs hr -> stairs hr
                $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'JONCTION_RAMPANT_RECT');
                $quantity = $numberAngles[self::REF_ANGLE_VERTICAL_STRAIGHT];
                $price = DCGCTools::getPriceHandRailCornerConnector($this->modelGetter,self::REF_ANGLE_VERTICAL_STRAIGHT,  $this->handrailHasPVColor, $this->handrailColorProperty);
                if ($this->handrailHasPVColor) {
                    list($pricePV, $unit) = DCGCTools::getPriceFinish($this->modelGetter, $this->totalProjectPrice);
                    $coloredOptionPrice = DCGCTools::getPriceFromPV($price, $pricePV, $unit);
                    $price += $coloredOptionPrice;
                }
                if (!empty($quantity) && !empty($price)) {
                    $categoryGlobal->addChild(AFDescriptifTarifItem::createItem($label, $price, $quantity));
                }
            }
        }
    }

    /**
     * Tarifications des options simples
     * @param AFDescriptifTarifCategorie $categoryOption
     * @param null $referenceCategories
     * @throws Exception
     */
    protected function genDetailsSimpleOptions(AFDescriptifTarifCategorie $categoryOption, $referenceCategories = null): void
    {
        if ($this->modelGetter->moduleExists(FacSimpleOption::NAME)) {
            /** @var FacSimpleOption $module */
            $module = $this->modelGetter->getModule(FacSimpleOption::NAME);
            $hasStairs = DCGCTools::hasStairs($this->modelGetter);
            $isTablePose = DCGCTOOLS::isTablePose($this->modelGetter);
            $stateOptions = $module->getOptionsManager()->getCurrentOptionsState();
            if (!empty($stateOptions)) {
                if ($referenceCategories) {
                    $labelCategory = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'OPTIONS_ACC');
                    $categoryGlobalOptions = AFDescriptifTarifCategorie::createCategorie($labelCategory);
                    $categoryOption->addChild($categoryGlobalOptions);
                } else {
                    $categoryGlobalOptions = $categoryOption;
                }
                foreach ($stateOptions as $info) {
                    $label = $info['label'];
                    $reference = $info['reference'];
                    [, , $optionName] = explode('.', $reference);
                    //quantity
                    $qty = 0;
                    $qtyStraight = 0;
                    if (array_key_exists('number', $info)) {
                        $qty = $info['number'];
                    }
                    //sub value?
                    $value = null;
                    $valueRef = null;
                    if (array_key_exists('value', $info)) {
                        $value = $info['value'];
                        $valueRef = $info['valueRef'];
                    }
                    //gen label
                    $libOption = $label;
                    if ($value !== null) {
                        $libOption = [];
                        $libOption[] = Translatable::addSuffix($label, ' - ');
                        $libOption[] = $value;
                        $libOption = Translatable::create($libOption);
                    }
                    //price
                    $usedReference = $valueRef ? $valueRef : $reference;
                    list($price, $unit, $qtyDefault) = GCTools::getDatasPrixOption($this->modelGetter,
                        $usedReference);
                    if ($price) {
                        $qty = DCGCTools::getLengthStairs($this->modelGetter);
                        $qtyStraight = DCGCTools::getLengthStraight($this->modelGetter);
                        if ($optionName === 'CACHE_MC') {
                            if ($qtyStraight) {
                                if ($this->railingColorProperty['pricePV'] !== null) $straightPrice = DCGCTools::applyPVToPrice($price, $this->railingColorProperty['pricePV'], $this->railingColorProperty['unit']);
                                else if ($this->handrailColorProperty['pricePV'] !== null) $straightPrice = DCGCTools::applyPVToPrice($price, $this->handrailColorProperty['pricePV'], $this->railingColorProperty['unit']);
                                $libOption = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'CACHE_MC_DROIT');
                                $item = AFDescriptifTarifItem::createItem($libOption, $straightPrice, $qtyStraight, $unit, 2);
                                $categoryGlobalOptions->addChild($item);
                            }
                            if (!$hasStairs) {
                                break;
                            } else {
                                list($pvRampant, $uniteRampant) = GCTools::getDatasPrixOption($this->modelGetter, "PV_RAMPANT");
                                if (isset($pvRampant) && $pvRampant) $stairPrice = DCGCTools::applyPVToPrice($price, $pvRampant, $uniteRampant);
                                if ($this->railingColorProperty['pricePV'] !== null) $stairPrice = DCGCTools::applyPVToPrice($stairPrice, $this->railingColorProperty['pricePV'], $this->railingColorProperty['unit']);
                                else if ($this->handrailColorProperty['pricePV'] !== null) $stairPrice = DCGCTools::applyPVToPrice($stairPrice, $this->handrailColorProperty['pricePV'], $this->railingColorProperty['unit']);
                                $libOption = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'CACHE_MC_RAMPANT');
                            }
                        }
                        if ($optionName === 'CACHE_MC_LISSE') {
                            if ($qtyStraight) {
                                if ($this->railingColorProperty['pricePV'] !== null) $straightPrice = DCGCTools::applyPVToPrice($price, $this->railingColorProperty['pricePV'], $this->railingColorProperty['unit']);
                                else if ($this->handrailColorProperty['pricePV'] !== null) $straightPrice = DCGCTools::applyPVToPrice($price, $this->handrailColorProperty['pricePV'], $this->railingColorProperty['unit']);
                                $libOption = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'CACHE_MC_LISSE_DROIT');
                                $item = AFDescriptifTarifItem::createItem($libOption, $straightPrice, $qtyStraight, $unit, 2);
                                $categoryGlobalOptions->addChild($item);
                            }
                            if (!$hasStairs) {
                                break;
                            } else {
                                list($pvRampant, $uniteRampant) = GCTools::getDatasPrixOption($this->modelGetter, "PV_RAMPANT");
                                if (isset($pvRampant) && $pvRampant) $stairPrice = DCGCTools::applyPVToPrice($price, $pvRampant, $uniteRampant);
                                if ($this->railingColorProperty['pricePV'] !== null) $stairPrice = DCGCTools::applyPVToPrice($stairPrice, $this->railingColorProperty['pricePV'], $this->railingColorProperty['unit']);
                                else if ($this->handrailColorProperty['pricePV'] !== null) $stairPrice = DCGCTools::applyPVToPrice($stairPrice, $this->handrailColorProperty['pricePV'], $this->railingColorProperty['unit']);
                                $libOption = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'CACHE_MC_LISSE_RAMPANT');
                            }
                        }
                        if ($optionName === 'CHEVILLE_FIXATION' && $isTablePose) {
                            list($price, $unit, $qtyDefault) = GCTools::getDatasPrixOption($this->modelGetter, "CHEVILLE_FIXATION_MURALE");
                            $libOption = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'CHEVILLE_FIXATION_MURALE');
                        }
                        //droit
                        if ($optionName === 'CHEVILLE_FIXATION') {
                            $qty = floor(DCGCTools::computeNumberOfFixationScrews($this->modelGetter));
                            $unit = null;
                        }
                        if ($optionName === 'CHEVILLE_FIXATION_MURALE') {
                            $qty = DCGCTools::computeNumberOfWallScrews($this->modelGetter);
                            $unit = null;
                        }
                        if ($qty == 0) {
                            list($qty, $unit) = GCTools::getQuantityByUnite($this->modelGetter, $unit);
                        }
                        if ($qty > $qtyDefault) {
                            $qty -= $qtyDefault;
                        }
                        $item = AFDescriptifTarifItem::createItem($libOption, $price, $qty, $unit, 2);
                    } else {
                        $item = AFDescriptifTarifItem::createItemSimpleDescription($libOption);
                    }
                    $categoryGlobalOptions->addChild($item);
                }
            }
        }
    }

    /**
     * @param AFDescriptifTarifCategorie $categorySegment
     * @param $segment
     * @param $isStair
     * @return void
     * @throws Exception
     */
    protected function genDetailsStAndrewCross(AFDescriptifTarifCategorie $categorySegment, $segment, $isStair): void
    {
        $modelGetter = $segment->getModelGetter();
        $quantity = DCGCTools::getAllStAndrewCrossBySegment($segment);
        $title = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'CROIX_SAINT_ANDRE');
        $price = DCGCTools::getPriceStAndrewCross($this->railingHasPVColor, $this->modelGetter, $this->totalProjectPrice);
        if ($quantity > 0) {
            if ($isStair) {
                list($pvRampant, $uniteRampant,) = DCGCTools::getDatasPrixOption($modelGetter, 'PV_RAMPANT');
                if (isset($pvRampant) && $pvRampant > 0) {
                    GCTools::applyPVToPrice($price, $pvRampant, $uniteRampant);
                }
            }
            $categorySegment->addChild(AFDescriptifTarifItem::createItem($title, $price, $quantity, ''));
        }
    }

    /**
     * Affichage du forfait de coloration hors standard
     * @throws Exception
     */
    protected function genDetailsNonStandardColorPackage(AFDescriptifTarifCategorie $categoryOption): void
    {
        $packagePrice = DCGCTools::getPriceNonStandardColorPackage($this->modelGetter);
        $title = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'FORFAIT');
        $categoryOption->addChild(AFDescriptifTarifItem::createItem($title, $packagePrice));
    }

    /**
     * Tarification des finitions dans le context global
     * @param AFDescriptifTarifCategorie $categoryGlobal
     * @throws Exception
     */
    protected function genDetailsGlobalFinishes(AFDescriptifTarifCategorie $categoryGlobal): void
    {
        $globalFinishes = AFModuleAdvanced::getDataInModule($this->modelGetter,
            AFModuleFinitionMF::NAME,
            AFModuleFinitionMF::FINITION);
        // ---------------------------------------------------------------- Railing Color in global context
        if ($globalFinishes !== null) {
            if ($this->railingColorProperty['pricePV'] > 0) {
                // -------------------------------------------------------- Warning message on pricing table
                if (empty($this->arrayMessage)) $this->arrayMessage[] = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'NON_STANDARD_CHOICE_WARNING_MESSAGE');
                // -------------------------------------------------------  For the finishes, the price increase is in % and applied on segment's cost with options
                $categoryGlobal->addChild(AFDescriptifTarifItem::createItemSimpleDescription($this->railingColorProperty['colorTitle']));
            } else {
                $categoryGlobal->addChild(AFDescriptifTarifItem::createItemSimpleDescription($this->railingColorProperty['colorTitle']));
            }
        }
        // ---------------------------------------------------------------- Dual-colouring
        if ($this->modelGetter->moduleExists(AFMulticolorationModule::NAME)) {
            /* @var $colorModule AFMulticolorationModule */
            $colorModule = $this->modelGetter->getModule(AFMulticolorationModule::NAME);
            // ------------------------------------------------------------ Handrail dual-colouring
            if ($colorModule->isItemKeyExists(self::REF_ITEM_HANDRAIL)) {
                $isActiveMC = $colorModule->isFinitionActive(self::REF_ITEM_HANDRAIL);
                if ($isActiveMC) {
                    if ($this->handrailColorProperty['color'] === 'ALL_RAL') $this->arrayNonStandardColor[] = $this->handrailColorProperty['color'];
                    if ($this->handrailColorProperty['pricePV'] > 0) {
                        if (empty($this->arrayMessage)) $this->arrayMessage[] = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'NON_STANDARD_CHOICE_WARNING_MESSAGE');
                        $categoryGlobal->addChild(AFDescriptifTarifItem::createItemSimpleDescription($this->handrailColorProperty['colorTitle']));
                        if ($this->handrailColorProperty['optionPrice'] > $this->highestPV) {
                            $this->highestPV = $this->handrailColorProperty['optionPrice'];
                        }
                    } else {
                        $categoryGlobal->addChild(AFDescriptifTarifItem::createItemSimpleDescription($this->handrailColorProperty['colorTitle']));
                    }
                }
            }
        }
    }


    /**********
     * Options in segment context
     */

    /**
     * Tarification des fixations poteaux
     * @param AFDescriptifTarifCategorie $categoryOption
     * @throws Exception
     */
    protected function genDetailsModelFixationPost(AFDescriptifTarifCategorie $categoryOption): void
    {

        switch ($this->pose) {
            case strpos($this->pose, "NEZ"):
                $titlePose = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'FIXATION_NEZ_DALLE');
                break;
            case (strpos($this->pose, 'DALLE')):
            default:
                $titlePose = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'FIXATION_DALLE');
                break;
        }
        $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
            'TYPE_FIXATION_TARIF',
            ['fixation' => $titlePose]);
        $label->setRecursive(true);
        $numberOfPosts = DCGCTools::getNumberOfPosts($this->modelGetter);
        $itemTarif = AFDescriptifTarifItem::createItemSimpleDescription($label);
        $itemTarif->setQuantite($numberOfPosts);
        $itemTarif->isQuantitePrixVisible = true;
        $categoryOption->addChild($itemTarif);
    }

    /**
     * Tarification des finitions par segment (découpage par section)
     * @param AFDescriptifTarifCategorie $categorySegment
     * @param $modelGetterSegment
     * @throws Exception
     */
    protected function genDetailsFinishesBySegment(AFDescriptifTarifCategorie $categorySegment, $modelGetterSegment): void
    {
        // ------------------------------------------------------------------------------ Custom dual-colouring by section
        if ($modelGetterSegment->moduleExists(AFMulticolorationModule::NAME)) {
            $arrayTemp = [];
            $editableSections = GCTools::getAllEditableSectionsMG($modelGetterSegment);
            if (!empty($editableSections)) {
                $allSectionColored = DCGCTools::getAllSectionColored($this->modelGetter, $editableSections, $this->totalProjectPrice);
                if (!empty($allSectionColored)) {
                    foreach ($allSectionColored as $fillingKey) {
                        //get data from section
                        $finish = $fillingKey['finition'];
                        $pricePV = $fillingKey['PV'];
                        $unit = $fillingKey['unit'];
                        //if different from previous section
                        if (!in_array($finish, $arrayTemp)) {
                            $arrayTemp[] = $finish;
                            [, $color,] = explode('.', $finish);
                            if ($color === 'ALL_RAL') $this->arrayNonStandardColor[] = $color;
                            if ($pricePV > 0) {
                                if (empty($this->arrayMessage)) $this->arrayMessage[] = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'NON_STANDARD_CHOICE_WARNING_MESSAGE');
                                //Apply PV on segment price
                                $optionSegmentPrice = DCGCTools::getPriceFromPV($this->totalSegmentPrice, $pricePV, $unit);
                                //-------------------------------------------------------- comparaison des PV globales
                                if ($this->railingColorProperty['pricePV'] > $this->handrailColorProperty['pricePV']) {
                                    $globalPV = $this->railingColorProperty['optionPrice']; //toujours à 0 puisque check après en global
                                } else {
                                    $globalPV = $this->handrailColorProperty['optionPrice'];
                                }
                                $globalOptionPrice = $globalPV;
                                //-------------------------------------------------------- comparaison des PV des sections dans le segment
                                if ($optionSegmentPrice > $this->highestPV) {
                                    $this->highestPV = $optionSegmentPrice;
                                }
                                //-------------------------------------------------------- comparaison de la PV segment et PV globale
                                if ($globalOptionPrice > $optionSegmentPrice) {
                                    $this->highestPV = $globalOptionPrice;
                                }
                            }
                        }
                    }
                    // ------------------------------------------------------------------- Affichage de la PV la plus haute des colorations par segment
                    if ($this->highestPV > 0) {
                        $titleCouleurPV = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                            'COULEUR_PV');
                        $categorySegment->addChild(AFDescriptifTarifItem::createItem($titleCouleurPV, $this->highestPV));
                    }
                } else {
                    //-------------------------------------------------------------------- comparaison des PV globales
                    if ($this->railingColorProperty['optionPrice'] > $this->handrailColorProperty['optionPrice']) {
                        $this->highestPV = $this->railingColorProperty['optionPrice'];
                    } else {
                        $this->highestPV = $this->handrailColorProperty['optionPrice'];
                    }
                    if ($this->highestPV > 0) {
                        $titleCouleurPV = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                            'COULEUR_PV');
                        $categorySegment->addChild(AFDescriptifTarifItem::createItem($titleCouleurPV, $this->highestPV));
                    }
                }
            }
        }
    }

    /**********
     * Options in section context
     */

    /**
     * Tarification des modèles de remplissages vitrés et option toute largeur
     * @param AFDescriptifTarifCategorie $categorySegment
     * @param AFModelGetter $modelGetterSection
     * @param $sectionPrice
     * @param float $lengthMacSegment
     * @param bool $isStair
     * @param int $nbSections
     * @return void
     * @throws Exception
     */
    protected function genDetailsGlassBySection(AFDescriptifTarifCategorie $categorySegment, AFModelGetter $modelGetterSection, $sectionPrice, float $lengthMacSegment, bool $isStair, int $nbSections): void
    {
        $quantity = round(($lengthMacSegment / $nbSections) / 1000, 2, PHP_ROUND_HALF_UP);
        /** @var GCVitrageModule $glassModule */
        $glassModule = $modelGetterSection->getModule(GCVitrageModule::NAME);
        //Custom Model
        if ($glassModule->getIsPresent()) {
            $glassModel = $glassModule->getModele();
            list($pricePV, $unit) = DCGCTools::getPriceGlass($modelGetterSection, $isStair);
            $glassLabel = BDDAdvanced::getBDDItemByRoute($glassModel)->getItemListLib($this->translation);
            $glassTitle = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                'VITRAGE_TARIF',
                ['modele' => $glassLabel]);
            $glassTitle->setRecursive(true);
            if ($pricePV > 0) {
                $sectionPrice = $pricePV * $quantity;
                $categorySegment->addChild(AFDescriptifTarifItem::createItem($glassTitle, $pricePV, $quantity, $unit, 2));
            }
        }
        //Margin
        if ($modelGetterSection->moduleExists(DCGCVitragePropertiesModule::NAME)) {
            /** @var DCGCVitragePropertiesModule $vitragePropertiesModule */
            $vitragePropertiesModule = $modelGetterSection->getModule(DCGCVitragePropertiesModule::NAME);
            $typeMargin = $vitragePropertiesModule->getTypeMargin();
            [, , $type] = explode('.', $typeMargin);
            if ($type == 'TOUTE_LARGEUR_AVEC') {
                $libTypeMargin = BDDAdvanced::getBDDItemByRoute($typeMargin)->getItemListLib($this->translation);
                $titleTypeMargin = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                    'MARGIN_VITRAGE_TARIF',
                    ['type' => $libTypeMargin]);
                $titleTypeMargin->setRecursive(true);
                list($pricePV, $unit) = DCGCTools::getPriceGlassMargin($modelGetterSection, $isStair);
                if ($pricePV > 0) {
                    $optionPrice = $this->sectionPrice;
                    GCTools::getPriceFromPV($optionPrice, $pricePV, $unit);
                    list($qty, $unit) = GCTools::getQuantityByUnite($this->modelGetter, $unit);
                    $categorySegment->addChild(AFDescriptifTarifItem::createItem($titleTypeMargin, $optionPrice, $qty, $unit, 2));
                }
            }
        }
    }

    /**
     * Tarification des remplissages tôles ou barreaux avec coloration
     * @param $categorySegment
     * @param $modelGetterSection
     * @param $sectionPrice
     * @param $isStair
     * @param $hasMetalSheet
     * @param $hasBars
     * @return void
     * @throws Exception
     */
    protected function genDetailsMetalSheetOrBarsBySection($categorySegment, $modelGetterSection, $sectionPrice, $isStair, $hasMetalSheet, $hasBars): void
    {
        $colorModule = $modelGetterSection->getModule(AFMulticolorationModule::NAME);
        if ($hasMetalSheet) {
            $filling = self::REF_ITEM_METAL_SHEET;
            $metalSheetModel = AFModuleAdvanced::getDataInModule($modelGetterSection, GCToleModule::NAME, GCToleModule::MODELE);
            $metalSheetLabel = BDDAdvanced::getBDDItemByRoute($metalSheetModel)->getItemListLib($this->translation);
            $label = [];
            $label[] = Translatable::addSuffix($this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'TOLE'), ' : ');
            $label[] = $metalSheetLabel;
            $label = Translatable::create($label);
            if ($colorModule->isItemKeyExists($filling)) {
                $isActive = $colorModule->isFinitionActive($filling);
                if ($isActive) {
                    $finish = $colorModule->getFinition($filling);
                    $labelFinish = BDDAdvanced::getBDDItemByRoute($finish)->getItemListLib($this->translation);
                    $labelColor = [];
                    $labelColor[] = Translatable::addSuffix($this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS, 'COLORATION'), ' : ');
                    $labelColor[] = $labelFinish;
                    $labelColor = Translatable::create($labelColor);
                    $label->concat($labelColor, ' / ');
                }
            }
            list($pricePV, $unit) = DCGCTools::getMetalSheetPrice($modelGetterSection);
            if ($pricePV > 0) {
                GCTools::getPriceFromPV($sectionPrice, $pricePV, $unit);
                list($qty, $unit) = GCTools::getQuantityByUnite($modelGetterSection, $unit);
                $categorySegment->addChild(AFDescriptifTarifItem::createItem($label, $sectionPrice, $qty, $unit));
            } else {
                $categorySegment->addChild(AFDescriptifTarifItem::createItemSimpleDescription($label));
            }
        } elseif ($hasBars) {
            $filling = self::REF_ITEM_BARS;
            if ($colorModule->isItemKeyExists($filling)) {
                $isActiveBars = $colorModule->isFinitionActive($filling);
                if ($isActiveBars) {
                    $finish = $colorModule->getFinition($filling);
                    $colorDescription = $this->getFinishesDescription($finish, $filling);
                    $categorySegment->addChild(AFDescriptifTarifItem::createItemSimpleDescription($colorDescription));
                }
            }
        }
    }

    /****************************
     * TOOLS
     */

    /************
     * GETTERS
     */

    /**
     * Retourne pour chacune des finitions la description associé pour la tarification
     * @param $finish
     * @param $filling
     * @return Translatable
     * @throws Exception
     */
    protected function getFinishesDescription($finish, $filling): Translatable
    {
        $label = BDDAdvanced::getBDDItemByRoute($finish)->getItemListLib($this->translation);
        switch ($filling) {
            case "tole" :
                $description = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                    'COLORATION_TOLE_TARIF',
                    ['color' => $label]);
                break;
            case "remplissage-barreaux" :
                $description = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                    'COLORATION_BARREAUX_TARIF',
                    ['color' => $label]);
                break;
            default:
                $description = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                    'COULEUR_TARIF',
                    ['color' => $label]);
        }
        $description->setRecursive(true);
        return $description;
    }


    /************
     * SETTERS
     */

    /**
     * Récupère la plus-value de la coloration du GC et calcul le prix de l'option
     * @return void
     * @throws Exception
     */
    protected function setRailingColorData(): void
    {
        $optionPrice = 0;
        $globalFinishes = AFModuleAdvanced::getDataInModule($this->modelGetter,
            AFModuleFinitionMF::NAME,
            AFModuleFinitionMF::FINITION);
        if ($globalFinishes != null) {
            $finishLabel = BDDAdvanced::getBDDItemByRoute($globalFinishes)->getItemListLib($this->translation);
            $finishTitle = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                'COULEUR_TARIF',
                ['color' => $finishLabel]);
            $finishTitle->setRecursive(true);
            [, $color,] = explode('.', $globalFinishes);
            if ($color === 'ALL_RAL') $this->arrayNonStandardColor[] = $color;
            list($pricePV, $unit) = DCGCTools::getPriceFinish($this->modelGetter, $this->totalProjectPrice);
            if ($pricePV > 0) {
                $optionPrice = DCGCTools::getPriceFromPV($this->totalSegmentPrice, $pricePV, $unit);
            }
            $this->railingHasPVColor = $pricePV > 0;
            $this->railingColorProperty['pricePV'] = $pricePV;
            $this->railingColorProperty['unit'] = $unit;
            $this->railingColorProperty['optionPrice'] = $optionPrice;
            $this->railingColorProperty['colorTitle'] = $finishTitle;
        }
    }

    /**
     * Récupère la plus-value de la coloration de la main-courante et calcul le prix de l'option
     * @return void
     * @throws Exception
     */
    protected function setHandrailColorData(): void
    {
        $optionPrice = 0;
        if ($this->modelGetter->moduleExists(AFMulticolorationModule::NAME)) {
            $colorModule = $this->modelGetter->getModule(AFMulticolorationModule::NAME);
            if ($colorModule->isItemKeyExists(self::REF_ITEM_HANDRAIL)) {
                $isActiveMC = $colorModule->isFinitionActive(self::REF_ITEM_HANDRAIL);
                if ($isActiveMC) {
                    $handrailFinishes = $colorModule->getFinition(self::REF_ITEM_HANDRAIL);
                    $finishLabel = BDDAdvanced::getBDDItemByRoute($handrailFinishes)->getItemListLib($this->translation);
                    $finishTitle = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                        'BICOLORE_MC_TARIF',
                        ['color' => $finishLabel]);
                    $finishTitle->setRecursive(true);
                    [, $color,] = explode('.', $handrailFinishes);
                    list($pricePV, $unit) = DCGCTools::getPriceBiColoration($this->modelGetter, $colorModule, self::REF_ITEM_HANDRAIL, self::GS_COLUMN_HANDRAIL_FINISH, self::REF_TABLE_PRICING_HANDRAIL_FINISHES, $this->totalProjectPrice);
                    if ($pricePV > 0) {
                        $optionPrice = DCGCTools::getPriceFromPV($this->totalSegmentPrice, $pricePV, $unit);
                    }
                    $this->handrailHasPVColor = $pricePV > 0;
                    $this->handrailColorProperty['pricePV'] = $pricePV;
                    $this->handrailColorProperty['unit'] = $unit;
                    $this->handrailColorProperty['color'] = $color;
                    $this->handrailColorProperty['optionPrice'] = $optionPrice;
                    $this->handrailColorProperty['colorTitle'] = $finishTitle;
                }
            }
        }
    }

    /************
     * OTHERS
     */

    /**
     * Créé un itemDescriptifTarif pour un une section de GC
     * @param AFModelGetter $modelGetterSection : modelGetter la section qu'on tarifie
     * @param bool $isStair
     * @param float $lengthSectionTarifaire
     * @return AFDescriptifTarifItem
     * @throws Exception
     */
    protected function createItemTarifSection(AFModelGetter $modelGetterSection, bool $isStair, float $lengthSectionTarifaire): AFDescriptifTarifItem
    {
        $refModel = AFModuleAdvanced::getDataInModule($modelGetterSection,
            GCModeleRSMModule::NAME,
            GCModeleRSMModule::MODELE);
        $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
            'DETAIL_SECTION_MODELE',
            ['modele' => BDDAdvanced::getItemListLib($refModel, $this->translation)]);
        $label->setRecursive(true);
        $isTablePose = DCGCTOOLS::isTablePose($modelGetterSection);
        if (!$isTablePose) {
            $priceRailing = DCGCTools::getPriceRailing($isStair, $modelGetterSection);
            $quantity = round($lengthSectionTarifaire, 2, PHP_ROUND_HALF_UP);
            $this->sectionPrice = ($priceRailing * $quantity);
            $unit = 'ml';
        } else {
            $priceRailing = DCGCTools::getPriceTablePose($modelGetterSection) * ($lengthSectionTarifaire / 1000);
            $priceRailing += DCGCTools::getPriceWallBracketTablePose($this->modelGetter) * 2;
            $quantity = 1;
            $this->sectionPrice = ($priceRailing * $quantity);
            $unit = '';
        }
        if ($priceRailing > 0) {
            $itemTarif = AFDescriptifTarifItem::createItem($label, $priceRailing, $quantity, $unit, 2);
        } else {
            $itemTarif = AFDescriptifTarifItem::createItemSimpleDescription($label);
        }
        return $itemTarif;
    }

    /**
     * @param bool $isStair
     * @param float $formattedLength
     * @param AFModelGetter $modelGetterSegment
     * @return Translatable
     * @throws Exception
     */
    protected function getTitleProduct(bool $isStair, float $formattedLength, AFModelGetter $modelGetterSegment): Translatable
    {
        //Height
        $customHeight = 1035;
        if ($this->modelGetter->moduleExists(GCCustomHeightAboveMC::NAME)) {
            /* @var $moduleHeight GCCustomHeightAboveMC */
            $moduleHeight = $this->modelGetter->getModule(GCCustomHeightAboveMC::NAME);
            $customHeight = $moduleHeight->getHeightAboveMC();
        }
        $fixation = $this->modelGetter->getModule(AbstractGCTypePoseGlobaleModule::NAME)->getData(GCTypePoseGlobaleMonoModule::TYPE_POSE_GLOBALE);
        $titleProduct = [];
        // Manage railing stair
        if ($isStair) {
            $typeSegment = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                'SEGMENT_TARIF_RAMPANT');
        } // Manage railing on terrace
        else {
            $typeSegment = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                'SEGMENT_TARIF_DROIT');
        }
        $label = $this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
            'SEGMENT_TARIF',
            ['type' => $typeSegment, 'ML' => $formattedLength, 'fixation' => BDDAdvanced::getItemListLib($fixation, $this->translation), 'height' => $customHeight / 1000]);
        $label->setRecursive(true);
        $titleProduct[] = $label;
        // Manage railing on terrace
        if (DCGCTOOLS::isTablePose($modelGetterSegment)) {
            $titleProduct[] = Translatable::addPrefix($this->translation->getWordHtml(Translation::DOMAINE_GARDE_CORPS,
                'POSE_TABLEAU'),
                " ");
        }
        $titleProduct = Translatable::create($titleProduct);

        return $titleProduct;
    }


}