## maintaina config must be in conf.php first
export component=core
export version=3.0.0alpha1

/srv/www/horde/web/components/bin/horde-components git clone $component
cd /srv/git/$component
/srv/www/horde/web/components/bin/horde-components git branch $component FRAMEWORK_6_0 maintaina-bare
/srv/www/horde/web/components/bin/horde-components git checkout $component FRAMEWORK_6_0
/srv/www/horde/web/components/bin/horde-components update --new-version=$version --new-state=alpha --new-apistate=alpha --new-api=$version -G

## TODO: Edit .horde.yml require: versions to +1.0.0alpha1
/srv/www/horde/web/components/bin/horde-components composer
## This breaks for pear/pear!
sed -i 's/pear:/composer:/g' .horde.yml 
sed -i 's|pear.horde.org/Horde_|horde/|g' .horde.yml 
sed -i 's|php: .*|php: ^7|g' .horde.yml 
## visually check for differences
#exit;
## If all is green, run release

/srv/www/horde/web/components/bin/horde-components /srv/git/$component release for h6-maintaina

