{**
 * Quince Contacts: „Zapytaj specjalistę" na karcie produktu.
 *
 * Przycisk stoi obok „Pobierz ofertę", a szuflada pokazuje człowieka: zdjęcie,
 * imię, stanowisko, telefon i adres. Formularz jest pod nimi, bo klient najpierw
 * ma zobaczyć, do kogo pisze.
 *
 * Styl jest w motywie (components/expert.css), nie tutaj: moduł dostarcza treść,
 * wygląd należy do silnika. Zachowanie ma wyspa motywu `islands/expert.js`.
 *}
<div class="q-expert" data-q-expert-root
  data-url="{$quinceExpertUrl}"
  data-msg-empty="{l s='Fill in the email and the message.' d='Modules.Quincecontacts.Shop'}"
  data-msg-invalid="{l s='This is not a valid email address.' d='Modules.Quincecontacts.Shop'}"
  data-msg-failure="{l s='The message could not be sent. Please try again in a moment.' d='Modules.Quincecontacts.Shop'}">

  <button type="button" class="q-btn q-btn--secondary q-btn--quiet" data-q-expert-open>
    <svg class="q-btn__icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
      <path d="M8 1a5 5 0 0 0-5 5v1h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a6 6 0 1 1 12 0v6a2.5 2.5 0 0 1-2.5 2.5H9.366a1 1 0 0 1-.866.5h-1a1 1 0 1 1 0-2h1a1 1 0 0 1 .866.5H11.5A1.5 1.5 0 0 0 13 12h-1a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h1V6a5 5 0 0 0-5-5"/>
    </svg>
    <span class="q-btn__label">{l s='Ask an expert' d='Modules.Quincecontacts.Shop'}</span>
  </button>

  <dialog class="q-modal q-modal--drawer" data-q-expert aria-labelledby="q-expert-title">
    <div class="q-modal__head">
      {* Ikona wewnątrz tytułu i w barwie marki, tak jak w pozostałych szufladach. *}
      <h2 class="q-modal__title" id="q-expert-title">
        <svg class="q-modal__icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
          <path d="M8 1a5 5 0 0 0-5 5v1h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a6 6 0 1 1 12 0v6a2.5 2.5 0 0 1-2.5 2.5H9.366a1 1 0 0 1-.866.5h-1a1 1 0 1 1 0-2h1a1 1 0 0 1 .866.5H11.5A1.5 1.5 0 0 0 13 12h-1a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h1V6a5 5 0 0 0-5-5"/>
        </svg>
        {l s='Need a hand?' d='Modules.Quincecontacts.Shop'}
      </h2>
      <button class="q-modal__close" type="button" data-q-expert-close
              aria-label="{l s='Close' d='Shop.Theme.Actions'}">
        <svg width="22" height="22" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z"/></svg>
      </button>
    </div>

    <div class="q-modal__body">
      <div data-q-expert-form>
        {* Człowiek przed formularzem, wyśrodkowany: klient ma najpierw zobaczyć,
           do kogo pisze, i może zadzwonić zamiast pisać. Układ z makiety. *}
        <div class="q-expert__person">
          <span class="q-expert__avatar">
            {if $quinceExpert.photo}
              <img src="{$quinceExpertPhoto}" alt="" width="96" height="96" loading="lazy">
            {else}
              {l s='Specialist' d='Modules.Quincecontacts.Shop'}
            {/if}
          </span>

          <div class="q-expert__name">{$quinceExpert.name}</div>
          {if $quinceExpert.job}
            <div class="q-expert__role">{$quinceExpert.job}</div>
          {/if}
        </div>

        {* Telefon i adres jako kafle, nie przyciski: pismo zwykłej wielkości,
           włosowa kreska i ikona w barwie marki. Pełna metryka przycisku
           przykrywała tu formularz, który jest w tej szufladzie właściwą
           treścią. Wygląd z makiety `strona-produktu-alt.html`. *}
        <div class="q-expert__contacts">
          {if $quinceExpert.phone}
            <a class="q-expert__contact"
               href="tel:{$quinceExpert.phone|replace:' ':''}">
              <svg class="q-expert__contact-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M3.654 1.328a.678.678 0 0 0-1.015-.063L1.605 2.3c-.483.484-.661 1.169-.45 1.77a17.6 17.6 0 0 0 4.168 6.608 17.6 17.6 0 0 0 6.608 4.168c.601.211 1.286.033 1.77-.45l1.034-1.034a.678.678 0 0 0-.063-1.015l-2.307-1.794a.68.68 0 0 0-.58-.122l-2.19.547a1.75 1.75 0 0 1-1.657-.459L5.482 8.062a1.75 1.75 0 0 1-.46-1.657l.548-2.19a.68.68 0 0 0-.122-.58zM1.884.511a1.745 1.745 0 0 1 2.612.163L6.29 2.98c.329.423.445.974.315 1.494l-.547 2.19a.68.68 0 0 0 .178.643l2.457 2.457a.68.68 0 0 0 .644.178l2.189-.547a1.75 1.75 0 0 1 1.494.315l2.306 1.794c.829.645.905 1.87.163 2.611l-1.034 1.034c-.74.74-1.846 1.065-2.877.702a18.6 18.6 0 0 1-7.01-4.42 18.6 18.6 0 0 1-4.42-7.009c-.362-1.03-.037-2.137.703-2.877z"/></svg>
              <span>{$quinceExpert.phone}</span>
            </a>
          {/if}

          {if $quinceExpert.email}
            <a class="q-expert__contact"
               href="mailto:{$quinceExpert.email}">
              <svg class="q-expert__contact-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z"/></svg>
              <span>{$quinceExpert.email}</span>
            </a>
          {/if}
        </div>

        {* Napis z kreską po obu stronach: oddziela dwie drogi kontaktu, zamiast
           zapowiadać formularz kolejnym akapitem. *}
        <div class="q-expert__divider">{l s='or write to us' d='Modules.Quincecontacts.Shop'}</div>

        {* Pływająca etykieta jak w pozostałych formularzach motywu: napis siada
           w pustym polu, a przy pisaniu wchodzi w górną kreskę. *}
        <div class="q-float q-expert__field">
          <label class="form-label" for="q-expert-name">
            {l s='Your name' d='Modules.Quincecontacts.Shop'}
          </label>
          <input class="q-field form-control" type="text" id="q-expert-name"
                 name="name" placeholder=" " autocomplete="name" data-q-expert-name>
        </div>

        <div class="q-float q-expert__field">
          <label class="form-label required" for="q-expert-email">
            {l s='Your email address' d='Modules.Quincecontacts.Shop'}
          </label>
          <input class="q-field form-control" type="email" id="q-expert-email"
                 name="email" placeholder=" " autocomplete="email" data-q-expert-email>
        </div>

        <div class="q-float q-expert__field">
          <label class="form-label required" for="q-expert-message">
            {l s='Your message' d='Modules.Quincecontacts.Shop'}
          </label>
          <textarea class="q-field form-control" id="q-expert-message" rows="4"
                    name="message" placeholder=" " data-q-expert-message></textarea>
        </div>

        <p class="q-expert__hint" data-q-expert-hint aria-live="polite" hidden></p>

        {* Cztery zapory silnika: znacznik strony, nazwa formularza, pułapka
           i czas wypełnienia. Piąta, zewnętrzna, dochodzi z modułem oceny. *}
        <div data-q-expert-guard>
          {include file='_partials/form-guard.tpl' form_id='quince_expert'}
        </div>

        <input type="hidden" data-q-expert-product value="{$quinceExpertProduct|intval}">
      </div>

      {* Po wysłaniu formularz znika, zostaje potwierdzenie z imieniem osoby,
         która odpowie. *}
      <div class="q-expert__done" data-q-expert-done hidden>
        <svg class="q-expert__done-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
          <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
          <path d="m10.97 4.97-.02.022-3.473 4.425-2.093-2.094a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05"/>
        </svg>
        <h3 class="q-expert__done-title">{l s='The message is on its way' d='Modules.Quincecontacts.Shop'}</h3>
        <p class="q-expert__done-text">
          {l s='It went to' d='Modules.Quincecontacts.Shop'} <strong>{$quinceExpert.name}</strong>.
          {l s='The answer comes back to the address you gave.' d='Modules.Quincecontacts.Shop'}
        </p>
      </div>
    </div>

    <div class="q-modal__foot">
      <div class="q-expert__actions">
        <button class="q-btn q-btn--primary q-btn--lg q-btn--block" type="button" data-q-expert-send>
          <svg class="q-btn__icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576zm6.787-8.201L1.591 6.602l4.339 2.76z"/></svg>
          <span class="q-btn__label">{l s='Send the message' d='Modules.Quincecontacts.Shop'}</span>
        </button>
        <button class="q-btn q-btn--primary q-btn--lg q-btn--block" type="button" data-q-expert-done-close hidden>
          {l s='Close' d='Shop.Theme.Actions'}
        </button>
      </div>
    </div>
  </dialog>
</div>
