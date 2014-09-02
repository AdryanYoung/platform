citest:
	php tests/php/all.php

cleanup:
	php tests/php/test-uninstaller.php

install:
	php tools/install/dev_installer.php

profile:
	php tools/php/profile_release.php ./

push:
	php tools/php/push_changes.php

release:
	php tools/php/profile_release.php ./
	mkdir ./release
	mv ./release_profile.json ./release/release_profile.json
	php tools/php/copy_release.php ./ ./release

test:
	-php tests/php/all.php
	php tests/php/test-uninstaller.php