# FileVersionTree

## PHP prototip

Prototip prikaže Git zgodovino izbrane datoteke v brskalniku. Gumb »Izberi datoteko« odpre sistemsko okno; datoteko lahko izbereš iz katere koli mape, če je znotraj lokalnega Git repozitorija. Sistemskega okna prek PHP trenutno podpira samo macOS.

Zahteve: macOS, PHP 8.1+ in Git, dostopna iz ukazne vrstice.

V mapi projekta zaženi:

```sh
php -S 127.0.0.1:8000
```

Nato odpri <http://127.0.0.1:8000> in izberi datoteko iz želenega repozitorija. Prikaz je graf commitov: vozlišča so commiti, črte kažejo povezave do staršev, oznake vej so pri njihovih glavah, oranžna obroba pa označuje commit, ki je spremenil izbrano datoteko. Graf vključuje do 250 commitov ter lokalne in prenesene veje; oddaljene veje se prikažejo, če so bile pred tem prenesene z `git fetch`.

Klik na vozlišče pokaže podrobnosti commita. Ogled razlik je naslednji korak.
