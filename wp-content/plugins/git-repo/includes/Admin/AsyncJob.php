<?php

namespace WC\SmoothGenerator\Admin;

/**
 * Class AsyncJob.
 *
 * A Record Object to hold the current state of an async job.
 */
class AsyncJob {
	/**
	 * The slug of the generator.
	 *
	 * @var string
	 */
	public string $generator_slug = '';

	/**
	 * The total number of objects to generate.
	 *
	 * @var int
	 */
	public int $amount = 0;

	/**
	 * Additional args for generating the objects.
	 *
	 * @var array
	 */
	public array $args = array();

	/**
	 * The number of objects already generated.
	 *
	 * @var int
	 */
	public int $processed = 0;

	/**
	 * The number of objects that still need to be generated.
	 *
	 * @var int
	 */
	public int $pending = 0;

	/**
	 * AsyncJob class.
	 *
	 * @param array $data
	 */
	public function __construct( array $data = array() ) {
		$defaults = array(
			'generator_slug' => $this->generator_slug,
			'amount'         => $this->amount,
			'args'           => $this->args,
			'processed'      => $this->processed,
			'pending'        => $this->pending,
		);
		$data     = wp_parse_args( $data, $defaults );

		list(
			'generator_slug' => $this->generator_slug,
			'amount'         => $this->amount,
			'args'           => $this->args,
			'processed'      => $this->processed,
			'pending'        => $this->pending
		) = $data;
	}
}
