<?php

namespace WC\SmoothGenerator\Generator;

/**
 * Class CustomerInfo.
 *
 * Helper class for generating locale-specific coherent customer test data.
 */
class CustomerInfo {
	/**
	 * Get a country code for a country that the store is set to sell to, or validate a given country code.
	 *
	 * @param string|null $country_code ISO 3166-1 alpha-2 country code. E.g. US, ES, CN, RU etc.
	 *
	 * @return string|\WP_Error
	 */
	public static function get_valid_country_code( ?string $country_code = '' ) {
		$country_code = !empty( $country_code ) ? strtoupper( $country_code ) : '';

		if ( $country_code && ! WC()->countries->country_exists( $country_code ) ) {
			$country_code = new \WP_Error(
				'smoothgenerator_customer_invalid_country',
				sprintf(
					'No data for a country with country code "%s"',
					esc_html( $country_code )
				)
			);
		} elseif ( ! $country_code ) {
			$valid_countries = WC()->countries->get_allowed_countries();
			$country_code    = array_rand( $valid_countries );
		}

		return $country_code;
	}

	/**
	 * Retrieve locale data for a given country.
	 *
	 * @param string string $country_code ISO 3166-1 alpha-2 country code. E.g. US, ES, CN, RU etc.
	 *
	 * @return array
	 */
	protected static function get_country_locale_info( string $country_code = 'en_US' ) {
		$all_locale_info = include WC()->plugin_path() . '/i18n/locale-info.php';

		if ( ! isset( $all_locale_info[ $country_code ] ) ) {
			return array();
		}

		return $all_locale_info[ $country_code ];
	}


	/**
	 * Get a localized Faker library instance.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code. E.g. US, ES, CN, RU etc.
	 *
	 * @return \Faker\Generator
	 */
	protected static function get_faker( $country_code = 'en_US' ) {
		$locale_info    = self::get_country_locale_info( $country_code );
		$default_locale = ! empty( $locale_info['default_locale'] ) ? $locale_info['default_locale'] : 'en_US';

		$faker = \Faker\Factory::create( $default_locale );

		return $faker;
	}

	/**
	 * Retrieve the localized instance of a particular provider from within the Faker.
	 *
	 * @param \Faker\Generator $faker         The current instance of the Faker.
	 * @param string           $provider_name The name of the provider to retrieve. E.g. 'Person'.
	 *
	 * @return \Faker\Provider\Base|null
	 */
	protected static function get_provider_instance( \Faker\Generator $faker, string $provider_name ) {
		$instance = null;
		foreach ( $faker->getProviders() as $provider ) {
			if ( str_ends_with( get_class( $provider ), $provider_name ) ) {
				$instance = $provider;
				break;
			}
		}

		return $instance;
	}

	/**
	 * Generate data for a person, localized for a particular country.
	 *
	 * Includes first name, last name, username, email address, and password.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code. E.g. US, ES, CN, RU etc.
	 *
	 * @return string[]|\WP_Error
	 * @throws \ReflectionException
	 */
	public static function generate_person( string $country_code = '' ) {
		$country_code = self::get_valid_country_code( $country_code );
		if ( is_wp_error( $country_code ) ) {
			return $country_code;
		}

		$faker = self::get_faker( $country_code );

		$first_name = $faker->firstName( $faker->randomElement( array( 'male', 'female' ) ) );
		$last_name  = $faker->lastName();

		if ( $faker->randomDigit() < 3 ) {
			// 30% chance for no capitalization.
			$first_name = strtolower( $first_name );
			$last_name  = strtolower( $last_name );
		}

		$person = array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'password'   => 'password',
		);

		// Make sure email and username use the same first and last name that were previously generated.
		$person_provider    = self::get_provider_instance( $faker, 'Person' );
		$reflected_provider = new \ReflectionClass( $person_provider );
		$orig_fn_male       = $reflected_provider->getStaticPropertyValue( 'firstNameMale', array() );
		$orig_fn_female     = $reflected_provider->getStaticPropertyValue( 'firstNameFemale', array() );
		$orig_ln            = $reflected_provider->getStaticPropertyValue( 'lastName', array() );

		$reflected_provider->setStaticPropertyValue( 'firstNameMale', array( $first_name ) );
		$reflected_provider->setStaticPropertyValue( 'firstNameFemale', array( $first_name ) );
		$reflected_provider->setStaticPropertyValue( 'lastName', array( $last_name ) );

		$person['display_name'] = $faker->name();

		// Switch Faker to default locale if transliteration fails or there's another issue.
		try {
			$faker->safeEmail();
			$faker->userName();
		} catch ( \Exception $e ) {
			$faker = self::get_faker();
		}

		do {
			$person['email'] = $faker->safeEmail();
		} while ( email_exists( $person['email'] ) );

		do {
			$person['username'] = $faker->userName();
		} while ( username_exists( $person['username'] ) );

		$reflected_provider->setStaticPropertyValue( 'firstNameMale', $orig_fn_male );
		$reflected_provider->setStaticPropertyValue( 'firstNameFemale', $orig_fn_female );
		$reflected_provider->setStaticPropertyValue( 'lastName', $orig_ln );

		return $person;
	}

	/**
	 * Generate data for a company, localized for a particular country.
	 *
	 * Includes company name, username, email address, and password.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code. E.g. US, ES, CN, RU etc.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function generate_company( string $country_code = '' ) {
		$country_code = self::get_valid_country_code( $country_code );
		if ( is_wp_error( $country_code ) ) {
			return $country_code;
		}

		$faker = self::get_faker( $country_code );

		$last_names = array();
		for ( $i = 0; $i < 3; $i++ ) {
			try {
				$last_names[] = $faker->unique()->lastName();
			} catch ( \OverflowException $e ) {
				$last_names[] = $faker->unique( true )->lastName();
			}
		}

		if ( $faker->randomDigit() < 3 ) {
			// 30% chance for no capitalization.
			$last_names = array_map( 'strtolower', $last_names );
		}

		// Make sure all the company-related strings draw from the same set of last names that were previously generated.
		$person_provider    = self::get_provider_instance( $faker, 'Person' );
		$reflected_provider = new \ReflectionClass( $person_provider );
		$orig_ln            = $reflected_provider->getStaticPropertyValue( 'lastName', array() );

		$reflected_provider->setStaticPropertyValue( 'lastName', $last_names );

		$company = array(
			'company'  => $faker->company(),
			'password' => 'password',
		);

		$company['display_name'] = $company['company'];

		$reflected_provider->setStaticPropertyValue( 'lastName', array( $faker->randomElement( $last_names ) ) );

		// Make sure a unique email and username are used.
		do {
			try {
				$company['email'] = $faker->companyEmail();
			} catch ( \Exception $e ) {
				$default_faker    = self::get_faker();
				$company['email'] = $default_faker->email();
			}
		} while ( email_exists( $company['email'] ) );

		do {
			try {
				$company['username'] = $faker->domainWord() . $faker->optional()->randomNumber( 2 );
			} catch ( \Exception $e ) {
				$default_faker       = self::get_faker();
				$company['username'] = $default_faker->userName();
			}
		} while ( username_exists( $company['username'] ) || strlen( $company['username'] ) < 3 );

		$reflected_provider->setStaticPropertyValue( 'lastName', $orig_ln );

		return $company;
	}

	/**
	 * Generate address data, localized for a particular country.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code. E.g. US, ES, CN, RU etc.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function generate_address( string $country_code = '' ) {
		$country_code = self::get_valid_country_code( $country_code );
		if ( is_wp_error( $country_code ) ) {
			return $country_code;
		}

		$faker = self::get_faker( $country_code );

		$address = array(
			'address1' => '',
			'city'     => '',
			'state'    => '',
			'postcode' => '',
			'country'  => '',
			'phone'    => '',
		);

		$exceptions = WC()->countries->get_country_locale();
		foreach ( array_keys( $address ) as $line ) {
			if ( isset( $exceptions[ $country_code ][ $line ]['hidden'] ) && true === $exceptions[ $country_code ][ $line ]['hidden'] ) {
				continue;
			}

			if ( isset( $exceptions[ $country_code ][ $line ]['required'] ) && false === $exceptions[ $country_code ][ $line ]['required'] ) {
				// 50% chance to skip if it's not required.
				if ( $faker->randomDigit() < 5 ) {
					continue;
				}
			}

			switch ( $line ) {
				case 'address1':
					$address[ $line ] = $faker->streetAddress();
					break;
				case 'city':
					$address[ $line ] = $faker->city();
					break;
				case 'state':
					$states           = WC()->countries->get_states( $country_code );
					if ( is_array( $states ) ) {
						$address[ $line ] = $faker->randomElement( array_keys( $states ) );
					}
					break;
				case 'postcode':
					$address[ $line ] = $faker->postcode();
					break;
				case 'country':
					$address[ $line ] = $country_code;
					break;
				case 'phone':
					$address[ $line ] = $faker->phoneNumber();
					break;
			}
		}

		return $address;
	}
}
