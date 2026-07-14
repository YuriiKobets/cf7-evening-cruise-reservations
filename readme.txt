=== Rezerwacje rejsu wieczornego dla CF7 ===
Contributors: custom
Tags: contact form 7, rezerwacje, kalendarz
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Rozszerzenie Contact Form 7 do globalnej rezerwacji miejsc na jeden rejs wieczorny dziennie.

== Opis ==

Wtyczka dodaje do Contact Form 7 dwa pola:

* Data rejsu
* Rezerwacja rejsu

Panel administracyjny znajduje się w menu: Rejsy wieczorne.

Wtyczka zapisuje wyłącznie agregowane dane potrzebne do zarządzania miejscami:

* data rejsu,
* liczba zarezerwowanych miejsc,
* informacja, czy dzień jest zarezerwowany na wyłączność,
* informacja, czy dzień jest wyłączony z kalendarza.

Wtyczka nie zapisuje danych osobowych z formularza CF7.

== Instalacja ==

1. Wgraj katalog wtyczki do wp-content/plugins/.
2. Aktywuj wtyczkę w panelu WordPress.
3. Upewnij się, że Contact Form 7 jest aktywny.
4. Przejdź do Rejsy wieczorne → Ustawienia i ustaw liczbę miejsc.
5. W edytorze formularza CF7 dodaj pola:

[ecr_date* data-rejsu]
[ecr_booking* rezerwacja-rejsu]

6. W zakładce Mail formularza dodaj tagi:

Data rejsu: [data-rejsu]
Rezerwacja: [rezerwacja-rejsu]

== Zasady dostępności ==

Dzień nie może zostać wybrany, jeżeli:

* został wyłączony w panelu administracyjnym,
* jest już zarezerwowany na wyłączność,
* nie ma wystarczającej liczby wolnych miejsc,
* użytkownik wybiera rezerwację na wyłączność, a dzień ma już wcześniejsze rezerwacje.

== Odinstalowanie ==

Usunięcie wtyczki usuwa jej tabelę oraz ustawienia.
