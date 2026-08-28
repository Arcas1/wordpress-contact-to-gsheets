# Build a WordPress-uploadable plugin zip.
#
#   make zip     -> build/contact-to-gsheets-v<VERSION>.zip
#   make build   -> stage the plugin tree without zipping (build/contact-to-gsheets/)
#   make test    -> run the PHPUnit suite
#   make clean   -> remove build/
#
# The zip contains a single top-level folder "contact-to-gsheets/" with runtime
# files only: the main file, uninstall.php, readme.txt, src/, and a production
# (no-dev) vendor/ trimmed to the Google Sheets service. Upload it at
# Plugins -> Add New -> Upload Plugin.

PLUGIN_SLUG := contact-to-gsheets
VERSION     := $(shell grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9.]+' $(PLUGIN_SLUG).php)
BUILD_DIR   := build
STAGE       := $(BUILD_DIR)/$(PLUGIN_SLUG)
ZIP         := $(BUILD_DIR)/$(PLUGIN_SLUG)-v$(VERSION).zip

# Files and directories copied verbatim into the package.
RUNTIME := $(PLUGIN_SLUG).php uninstall.php readme.txt src languages

.PHONY: all zip build test clean

all: zip

test:
	vendor/bin/phpunit

zip: build
	cd $(BUILD_DIR) && zip -qr $(PLUGIN_SLUG)-v$(VERSION).zip $(PLUGIN_SLUG) \
		-x '*.DS_Store' -x '*/.git/*'
	@echo "built $(ZIP) ($$(du -h $(ZIP) | cut -f1))"

build: clean
	@command -v composer >/dev/null || { echo "composer not found on PATH"; exit 1; }
	@test -n "$(VERSION)" || { echo "could not read Version from $(PLUGIN_SLUG).php"; exit 1; }
	mkdir -p $(STAGE)
	cp -R $(RUNTIME) $(STAGE)/
	cp composer.json composer.lock $(STAGE)/
	composer install --no-dev --no-scripts --prefer-dist --no-interaction \
		--working-dir=$(STAGE)
	# Keep only the Sheets service out of google/apiclient-services (~150MB otherwise).
	find $(STAGE)/vendor/google/apiclient-services/src -mindepth 1 -maxdepth 1 \
		! -name Sheets ! -name 'Sheets.php' -exec rm -rf {} +
	# Regenerate the classmap against the trimmed tree; drop nested VCS + build files.
	composer dump-autoload --no-dev --optimize --no-scripts --working-dir=$(STAGE)
	find $(STAGE)/vendor -type d -name .git -prune -exec rm -rf {} +
	rm -f $(STAGE)/composer.json $(STAGE)/composer.lock
	@echo "staged $(STAGE) ($$(du -sh $(STAGE) | cut -f1))"

clean:
	rm -rf $(BUILD_DIR)
