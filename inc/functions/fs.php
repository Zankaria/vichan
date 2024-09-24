<?php
namespace Vichan\Functions\Fs;

/**
 * Creates a hardlink to the source file or copies the source on failure.
 *
 * @param string $source_file The file that already exists.
 * @param string $target_file The link or copy that is to be created.
 * @return bool True if either hardlinking or copying succeeded.
 */
function link_or_copy(string $source_file, string $target_file): bool {
	if (!\link($source_file, $target_file)) {
		return \copy($source_file, $target_file);
	}
	return true;
}
