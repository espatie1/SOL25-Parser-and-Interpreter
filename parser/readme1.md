Implementační dokumentace k 1. úloze do IPP 2024/2025
Jméno a příjmení: Pavel Stepanov
Login: xstepa77

## Přehled
Skript `parse.py` analyzuje zdrojový kód jazyka SOL25. Provádí lexikální, syntaktickou a sémantickou analýzu a generuje XML reprezentaci abstraktního syntaktického stromu (AST).

## Struktura řešení

### 1. Lexikální analýza
- **Lexer:** Rozděluje vstupní kód do tokenů pomocí regulárních výrazů.
- Rozpoznává:
  - **Bílá místa a komentáře** (nezanořené, uzavřené v uvozovkách)
  - **Řetězcové literály** s escapováním speciálních znaků
  - **Čísla, identifikátory** a symboly (např. `:=`, `:`, `[`, `]`, `{`, `}`, `(`, `)`, `.`)

### 2. Syntaktická analýza
- **Parser:** Implementován metodou rekurzivního sestupu.
- **AST:** Vytváří instance tříd (Program, ClassDef, Method, Block, Parameter, Assignment, Var, Literal, Send) podle definované gramatiky.
- Ověřuje správný formát identifikátorů (např. třídy – první písmeno velké, metody – první písmeno malé) a správné pořadí tokenů.

### 3. Sémantická analýza
- **SemanticAnalyzer:** Kontroluje:
  - Duplicitní definice tříd a metod
  - Přítomnost třídy `Main` s metodou `run` bez parametrů
  - Správnou dědičnost (včetně kontroly cyklických odkazů)
  - Správné používání proměnných a rezervovaných identifikátorů
  - Metoda `lookup_method` rekurzivně vyhledává metody v uživatelských i vestavěných třídách.

### 4. Generování XML
  - **XML generátor:** Převádí AST do XML struktury:
  - Kořenový element `<program>` má atribut `language="SOL25"` a případně `description`
  - Třídy jsou reprezentovány elementy `<class>` s atributy `name` a `parent`
  - Metody a bloky jsou mapovány na elementy s podřízenými elementy pro parametry a přiřazení
  - Literály jsou speciálně ošetřeny (escapování apostrofů a speciálních znaků)

### 5. Řízení chyb
- Definované chybové kódy pokrývají lexikální, syntaktické, sémantické i interní chyby.
- Funkce `error_exit` vypisuje chybové zprávy a ukončuje program s příslušným kódem.

## Implementační detaily
- **Modulární design:** Oddělení lexikální, syntaktické a sémantické analýzy spolu s generováním XML usnadňuje údržbu.
- **Validace vstupu:** Přísné kontroly formátu identifikátorů a pořadí tokenů zajišťují robustní analýzu.
- **Rekurzivní zpracování:** Umožňuje efektivní analýzu zanořených výrazů a bloků.
