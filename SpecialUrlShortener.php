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
		$form->addFooterText(
			Html::rawElement( 'div', array( 'id' => 'mwe-urlshortener-form-footer' ),
				Html::element( 'span', array( 'id' => 'mwe-urlshortener-shortened-url-label' ),
					$this->msg( 'urlshortener-shortened-url-label')->text()
				) .
				Html::rawElement( 'div', array(
						// Using a div instead of an <input> so we don't have to worry about sizing the
						// input to match the length of the shortened URL
						'id' => 'mwe-urlshortener-shorturl-display',
					)
				)
			) .
			Html::rawElement( 'div', array( 'id' => 'mwe-urlshortener-form-error' ) )
		);

		$this->getOutput()->addModules( 'ext.urlShortener.special' );
		// Send Styles anyway, even without JS
		$this->getOutput()->addModuleStyles( 'ext.urlShortener.special.styles' );

	}


	/**
	 * Validate the URL to ensure that we are allowed to create a shorturl for this.
	 *
	 * @param $url String The URL to validate
	 * @param $allData Array All the form fields!
	 * @return bool|string true if url is valid, error message otherwise
	 */
	public function validateURL( $url, $allData ) {
		$validity_check =  UrlShortenerUtils::validateUrl( $url );
		if ( $validity_check === true ) {
			return true;
		}
		return $validity_check->text();
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
				'default' => $this->msg( 'urlshortener-url-input-submit' )->text(),
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
		$out->addModuleStyles( 'ext.urlShortener.special.styles' );

		$html = Html::element( 'input', array(
			'type' => 'text',
			'readonly' => true,
			'id' => 'mwe-urlshortener-shorturl-display',
			'value' => UrlShortenerUtils::makeUrl( UrlShortenerUtils::getShortCode( $data['url']  ) )
		));
		$out->addHTML( $html );
		return true;
	}
}
