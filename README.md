# CZ Highlights

**CZ Highlights** è un plugin WordPress che permette agli utenti registrati di evidenziare porzioni di testo negli articoli e nei volumi, aggiungere note personali e consultarle in una pagina dedicata.

---

## Funzionalità principali

- Evidenziazione di testo su articoli (`post`) e pagine volume con 5 colori selezionabili.
- Aggiunta e modifica di note personali su ogni evidenziazione tramite popover.
- Supporto articoli paginati (`<!--nextpage-->`): le evidenziazioni vengono associate alla pagina corretta e il pulsante "Vai" naviga alla pagina giusta.
- Fingerprinting contestuale (prefisso/suffisso) per riposizionare le evidenziazioni anche se il testo dell'articolo cambia lievemente; stato `displaced` se il contesto non corrisponde più, `orphaned` se il testo non è più trovabile.
- Drawer laterale accessibile da ogni articolo/volume: lista delle evidenziazioni con pulsanti Vai, Modifica, Elimina.
- Pagina **Le Mie Note** (`[czh_my_notes]`): creata automaticamente all'attivazione del plugin.
  - Lista volumi con numero di note per volume.
  - Card **Note sparse** per articoli non appartenenti a nessun volume.
  - Separatore visivo tra note sparse e volumi.
  - Vista dettaglio per volume/articoli singoli: note raggruppate per articolo in sezioni collassabili.
  - Pulsante **Comprimi/Espandi** tutto.
  - Modifica inline nota e cambio colore direttamente dalla pagina.
  - Conferma eliminazione con pannello inline (senza `window.confirm()`).
- Tooltip "Evidenzia" visibile anche per utenti non registrati: al click reindirizza alla pagina di login con redirect back all'articolo corrente.
- Voce **Le mie Note** nel menu utente (`czh_nav_user_menu_items`): apre il drawer su articoli/volumi, naviga alla pagina note altrove.
- Preferenza stile highlight (`underline` o `filled`) letta dal meta utente `czup_highlight_style` (impostato da `cz-user-preferences`).
- Rispetto della preferenza utente `czup_highlights_enabled`: se disabilitato nessun asset viene caricato.
- Asset minificati con fallback automatico ai file sorgente se `SCRIPT_DEBUG` è `true`.

---

## Dipendenze

- **cz-volume** (opzionale): se attivo, le note vengono raggruppate per volume. Senza di esso tutte le note appaiono nella card "Note sparse".
- **cz-user-preferences** (opzionale): gestisce le preferenze `czup_highlights_enabled` e `czup_highlight_style`.

---

## Requisiti

- WordPress 6.0+
- PHP 8.0+
- Node.js 18+ (solo per rebuild degli asset)

---

## Installazione

1. Copia la cartella `cz-highlights` in `wp-content/plugins/`.
2. Attiva il plugin da **Plugin > Plugin installati**.
3. All'attivazione vengono eseguiti automaticamente:
   - Creazione della tabella `{prefix}czh_highlights`.
   - Creazione della pagina **Le Mie Note** (slug `le-mie-note`) con shortcode `[czh_my_notes]`; l'ID viene salvato in `wp_options` come `czh_notes_page_id`.

---

## Endpoint REST

Namespace: `czh/v1`

| Metodo | Endpoint | Descrizione |
|--------|----------|-------------|
| `GET` | `/highlights?post_id=X` | Lista evidenziazioni per post |
| `POST` | `/highlights` | Crea evidenziazione |
| `PATCH` | `/highlights/{id}` | Aggiorna nota/colore/status |
| `DELETE` | `/highlights/{id}` | Elimina evidenziazione |
| `GET` | `/highlights/summary` | Volumi con conteggio note (+ card note sparse) |
| `GET` | `/highlights/volume?volume_id=X` | Note di un volume raggruppate per articolo |
| `GET` | `/highlights/standalone` | Note su articoli non appartenenti a nessun volume |

Tutti gli endpoint richiedono autenticazione (`is_user_logged_in()`).

---

## Build asset

```bash
node scripts/build.js
```

Genera:

- `assets/js/czh.min.js` + sourcemap
- `assets/css/czh.min.css` + sourcemap

---

## Schema DB

Tabella `{prefix}czh_highlights`:

| Colonna | Tipo | Note |
|---------|------|------|
| `id` | `BIGINT UNSIGNED` | PK auto-increment |
| `user_id` | `BIGINT UNSIGNED` | ID utente WordPress |
| `post_id` | `BIGINT UNSIGNED` | ID articolo o volume |
| `page_num` | `SMALLINT UNSIGNED` | Pagina `<!--nextpage-->`, default 1 |
| `selected_text` | `TEXT` | Testo evidenziato |
| `prefix_text` | `VARCHAR(200)` | Contesto pre-selezione |
| `suffix_text` | `VARCHAR(200)` | Contesto post-selezione |
| `note` | `TEXT` | Nota personale (nullable) |
| `color` | `VARCHAR(30)` | `yellow` \| `green` \| `blue` \| `pink` \| `orange` |
| `status` | `VARCHAR(20)` | `active` \| `displaced` \| `orphaned` |
| `created_at` | `DATETIME` | |
| `updated_at` | `DATETIME` | Aggiornato automaticamente |
