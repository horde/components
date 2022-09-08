#!/bin/bash
git checkout main

git branch -D release-ephemeral
git branch release-ephemeral
git checkout release-ephemeral
composer require php "^8.1" --ignore-platform-reqs
git add composer.json
git commit -m "Fixup composer.json for 8.1 release"
git tag v1.0.0.80100alpha1
git checkout main

git branch -D release-ephemeral
git branch release-ephemeral
git checkout release-ephemeral
composer require php "^7.4" --ignore-platform-reqs
git add composer.json
git commit -m "Fixup composer.json for 8.1 release"
git tag v1.0.0.70400alpha1
git checkout main
git push --tags
