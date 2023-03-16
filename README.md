# EXT: datamints_feuser

Online documentation: https://docs.typo3.org/typo3cms/extensions/datamints_feuser/

# Extended functionality
Captcha is now compatible with Powermail captcha

To use you can adjust the typoscript config:

```
captcha.use = powermail
captcha.class = your classes
captcha.reload_class = your reload classes
captcha.reload_icon_path = your path to an image
form.class = your form class
```

If ```captcha.reload_class``` is set there will be a span rendered where you can attach your captcha reload magick to

If ```captcha.reload_icon_path``` is set there, the image will be rendered

To add a class to the form, you can set ```form.class```
