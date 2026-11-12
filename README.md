# FileVersionTree

## PHP prototip

Prototip prikaže Git zgodovino izbrane datoteke v brskalniku. Gumb »Izberi datoteko« odpre sistemsko okno; datoteko lahko izbereš iz katere koli mape, če je znotraj lokalnega Git repozitorija. Sistemskega okna prek PHP trenutno podpira samo macOS.

Zahteve: macOS, PHP 8.1+ in Git, dostopna iz ukazne vrstice.

V mapi projekta zaženi:

```sh
php -S 127.0.0.1:8000
```

Nato odpri <http://127.0.0.1:8000> in izberi datoteko iz želenega repozitorija. Graf prikazuje samo commite, ki spreminjajo izbrano datoteko, ter povezave z najbližjimi starejšimi commiti, ki spreminjajo isto datoteko. Oznake vej so prikazane, kadar je glava veje med prikazanimi commiti. Prikaže do 250 commitov; upošteva lokalne in prenesene veje, oddaljene veje pa le, če so bile pred tem prenesene z `git fetch`.

Klik na vozlišče pokaže podrobnosti commita. Ogled razlik je naslednji korak.
