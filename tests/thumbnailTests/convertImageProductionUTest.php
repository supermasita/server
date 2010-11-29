<?php

require_once 'PHPUnit\Framework\TestCase.php';

require_once 'convertImageTester.php';
require_once 'bootstrap.php';

define("TESTSFILE", "convertImageProductionTests.txt");
define("IMAGESDIR", dirname(__FILE__) . '/images');

/**
 * this class tests the compatibility of a new code with the code  residinf in producion.
 * the myconverter::convertImage function will run on a known set of image files and convert them
 * using some parameters. the same will be done by calling to URL request (production side). after that
 * the two outputs will be compared.
 * @author ori
 */
class convertImageProductionUTest extends PHPUnit_Framework_TestCase {
	
	/**
	 * arrays of input data for tests.
	 * every element is a string file name (including or not including full path)
	 * $sourceFiles[$i] output will be tested with respect to $outputReferenceFiles[$i]
	 */
	private $sourceFiles = array();				// array of different sorce files
	private $outputReferenceFiles = array();	// array of different url requests
	
	/**
	 * Prepares the environment before running a test.
	 */
	protected function setUp() {
		parent::setUp ();
		$this->retrieveTestList(TESTSFILE, IMAGESDIR);
	}

	/**
	 * retrieve information from tests file
	 * @param unknown_type $testsFile - a path to the tests file. containing all tests in a specific format
	 * @param unknown_type $imagesDir - path to all images directory. needed for tests
	 */
	private function retrieveTestList($testsFile, $imagesDir) 
	{
		$fileHundler = null;
		if (($fileHundler = fopen($testsFile, "r")) === false)
			die ('unable to read tests file [' . $testsFile . ']');
		fgets($fileHundler);	// discard form header line
		while (!feof($fileHundler)) {
			$line = fgets($fileHundler);
			$line = explode("\t", $line);
			$this->sourceFiles[] = $imagesDir . '/' . trim($line[0]);
			$this->outputReferenceFiles[] = trim($line[1]);
		}
	}
	
	/**
	 * test convertImage function (myFileConverter::convertImage)
	 * the test is done by executing a number of different tests on different files and comparing result
	 * to the produvtion servert results
	 */
	public function testConvertImage()
	{
		$status = null;
		$tester = null;
		// test all source files and compare result to output reference file
		for ($i = 0; $i < count($this->sourceFiles); $i++) {			
			$tester = new convertImageTester($this->sourceFiles[$i], $this->outputReferenceFiles[$i]);

			// extract convertion parameters from $outputReferenceFile and update $tester for those parameters
			$params = array();
			$tmp = array();
			$tmp = explode("/", $this->outputReferenceFiles[$i]);
			// get rid of source file name and extension of file
			for ($j = 0; $j < 8 ; $j++)
				array_shift($tmp);
			array_pop($tmp);				
			$j = 0;
			while($j < count($tmp)) {
				$params["$tmp[$j]"] = $tmp[$j + 1];
				$j += 2;
			}
			array_key_exists('width', $params) ? $tester->setWidth($params['width']) :  $tester->setWidth();
			array_key_exists('height', $params) ? $tester->setHeight($params['height']) : $tester->setHeight();
			array_key_exists('type', $params) ? $tester->setCropType($params['type']) : $tester->setCropType();
			array_key_exists('bgcolor', $params) ? $tester->setBGColor($params['bgcolor']) : $tester->setBGColor();
			array_key_exists('quality', $params) ? $tester->setQuality($params['quality']) : $tester->setQuality();
			array_key_exists('src_x', $params) ? $tester->setSrcX($params['src_x']) : $tester->setSrcX();
			array_key_exists('src_y', $params) ? $tester->setSrcY($params['src_y']) : $tester->setSrcY();
			array_key_exists('src_w', $params) ? $tester->setSrcW($params['src_w']) : $tester->setSrcW();
			array_key_exists('src_h', $params) ? $tester->setSrcH($params['src_h']) : $tester->setSrcH();	
			
			// excute test and assert
			$status = $tester->execute();
			if ($status === false)
			{
				echo 'unable to convert [' . $tester->getSourceFile() . '] with parameterrs: ' .
					print_r($tester->getParams(), true) . PHP_EOL;
					unset($tester);
					continue;
			}
			
			// download from production the converted image (thumbnail) and
			// check if output is identical to reference output
			$tester->downloadUrlFile();
			$status = $tester->compareTargetReference();
			$tester->deleteDownloadFile();
			if ($status === false)
				echo 'images files: ['  . $tester->getOutputReferenceFile() . '], [' .
					$tester->getTargetFile(). '] are not identical' . PHP_EOL;
			echo 'convertImage test completed on file [' . $tester->getOutputReferenceFile() . ']' . PHP_EOL;
			unset($tester);
		}
	}
	
	/**
	 * Cleans up the environment after running a test.
	 */
	protected function tearDown() {
		parent::tearDown ();
	}
	
	/**
	 * Constructs the test case.
	 */
	public function __construct() {
		// TODO Auto-generated constructor
	}

}

