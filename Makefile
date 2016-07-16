BINARY=rmq-dumper
#=======================================================

build: vendor
	php build-phar.php --bin="$(BINARY)"

vendor:
	composer install --no-dev --optimize-autoloader

clean:
	-rm $(BINARY)

clean-vendor: clean
	-rm -rf vendor

install: $(BINARY)
	cp $(BINARY) /usr/local/bin/
