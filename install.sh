php recovery/install/index.php \
  --db-host=mysql \
  --db-user=root \
  --db-password=root \
  --db-name=sw57 \
  --shop-host=sw57.dev.localhost \
  --admin-username=demo \
  --admin-password=demo \
  --admin-email=test@test.de \
  --admin-locale=en_GB \
  --admin-name=Test \
  --shop-name=Test \
  --shop-email=test@test.de \
  --shop-currency=EUR \
  --shop-locale=en_GB \
  -n
echo $?