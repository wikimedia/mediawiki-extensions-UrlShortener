<?php
/**
 * A special page that provides redirects to articles via their page IDs
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2011 Yuvaraj Pandian (yuvipanda@yuvi.in)
 * @licence Modified BSD License
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	echo( 'not a valid entry point.\n' );
	die( 1 );
}

class SpecialUrlShortener extends FormSpecialPage {

	public function __construct() {
		parent::__construct( 'UrlShortener' );
	}

	/**
	 * Generate form to create new Short URLs if no param is passed.
	 *
	 * If a param is passed, attempt to redirect user to the page that corresponds to that.
	 *
	 * @param $par String|null base36 shortcode of the short URL, or null if we want the form.
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		if ( $par === null ) {
			$out->addModules( 'ext.urlShortener.special' );
			parent::execute( $par );
		} else {
			$url = UrlShortenerUtils::getURL( $par );
			if ( $url !== false ) {
				$out->redirect( $url, '301' );
			} else {
				$out->showErrorPage( 'urlshortener-not-found-title', 'urlshortener-not-found-message' );
			}
		}
	}

	/**
	 * Remove the legend wrapper and also use the agora styles.
	 * @param HTMLForm $form
	 */
	protected function alterForm( HTMLForm $form ) {
		$form->setWrapperLegend( false );
		$form->setDisplayFormat( 'raw' );
		$form->suppressDefaultSubmit( true );
		$form->addHeaderText(
			Html::element( "span", array( "id" => "mwe-urlshortener-form-header" ),
				$this->msg( 'urlshortener-form-header' )->text()
			)
		);
	}


	/**
	 * Validate the URL to ensure that we are allowed to create a shorturl for this.
	 *
	 * @param $url The URL to validate
	 * @param $allData All the form fields!
	 * @return bool|string true if url is valid, error message otherwise
	 */
	public function validateURL( $url, $allData ) {
		// TODO: Add more validation here!
		if ( wfParseUrl( $url ) !== false ) {
			return true;
		} else {
			return $this->msg( 'urlshortener-error-malformed-url' )->text();
		}
	}

	/**
	 * Create a fully qualified short URL for the given shortcode.
	 *
	 * @param $shortCode shortcode to generate URL For.
	 * @return String The fully qualified URL
	 */
	public function makeUrl( $shortCode ) {
		global $wgUrlShortenerTemplate, $wgServer;

		if ( !is_string( $wgUrlShortenerTemplate ) ) {
			$urlTemplate = SpecialPage::getTitleFor( $this->getName(), '$1' )->getFullUrl();
		} else {
			$urlTemplate = $wgServer . $wgUrlShortenerTemplate;
		}
		$url = str_replace( '$1', $shortCode, $urlTemplate );

		// Make sure the URL is fully qualified
		$url = wfExpandUrl( $url );

		return $url;
	}

	/**
	 * Generate the form used to input the URL to shorten.
	 * @return array A form defintion that can be used by HTMLForm
	 */
	protected function getFormFields() {
		return array(
			'url' => array(
				'class' => 'HTMLTextField',
				'validation-callback' => array( $this, 'validateURL' ),
				'required' => true,
				'type' => 'url',
				'id' => 'mwe-urlshortener-url-input',
				'placeholder' => $this->msg( 'urlshortener-url-input-label' )->text()
			),
			'submit' => array(
				'class' => 'HTMLSubmitField',
				'value' => $this->msg( 'urlshortener-url-input-submit' )->text(),
				'cssclass' => 'mw-ui-button mw-ui-progressive',
				'id' => 'mwe-urlshortener-url-submit'
			)
		);
	}

	/**
	 * Process the form on POST submission.
	 *
	 * Creates the short URL and displays it back to the user.
	 *
	 * @param array $data
	 * @return bool|array True for success, false for didn't-try, array of errors on failure
	 */
	public function onSubmit( array $data ) {
		$out = $this->getOutput();
		$out->addModules( 'ext.urlShortener.special' );

		$html = Html::element( 'input', array(
			'type' => 'text',
			'readonly' => true,
			'id' => 'mwe-urlshortener-url-textbox',
			'value' => $this->makeUrl( UrlShortenerUtils::getShortCode( $data['url']  ) )
		));
		$out->addHTML( $html );
		return true;
	}
}
