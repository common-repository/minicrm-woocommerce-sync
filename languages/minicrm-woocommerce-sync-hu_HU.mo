��    -      �  =   �      �  �   �  R   �       �        �  E   �  J   �     :  �   X               &     8     N  �   a     >  D   U     �     �     �  	   �     �     �  j   �  H   Y	  ]   �	  _    
  =   `
  .   �
  2   �
  e      Y   f  5   �  A   �  a   8     �  �   �  _   J  S  �  �   �  J  �  	   2     <     C  6  X  �   �  |   `  	   �  �   �  
     O   �  R   �  '   -  �   U  	   8     B     \     v     �  9  �     �  s   �     m     �  "   �  .   �     �     �  �     C   �  n   �  w   C  O   �  0     4   <  i   q  M   �  W   )  Z   �  f   �     C  �   a  s      �  �     <"  �  J#  	   %     %     %                   "                               
   &                     (         -   %   !          '          +         )              	                  *         #          $         ,                         <strong>If you change the label of a product option</strong>, it's recommended that you keep the old mapping and add another one with the new label, since previous orders stored the product option with its old label. <strong>Syncing</strong>... %s% Remaining time: %s Keep the window open to finish. API key An unexpected <strong class="minicrm-woocommerce-sync-error">error occured</strong>, the sync was aborted. (Please check the Sync log). Category ID Example:
T-shirt text:TextOnTshirt
Extended warranty:ExtendedWarranty Example:
shipping_postcode:PostcodeOfShipping
shipping_city:CityOfShipping Extra Product Options mapping Finished syncing all (%s) projects. <strong class="minicrm-woocommerce-sync-success">No error</strong> occured, but complete success can only be verified from the Sync log. Folder name Full billing address Full billing name Full shipping address Full shipping name If you sync multiple shops into a single MiniCRM account, you need to set a unique shop ID for each WooCommerce shop to avoid one shop overwriting another one's orders. If you sync a single shop only, then leave it on 0. MiniCRM account locale PHP extension "%s" is required, but not loaded on your installation. PostcodeOfShipping Shop ID Sync all shop orders System ID Test syncing TextOnTshirt The API key is required and should consist of 32 alphanumeric characters. Please type the correct setting. The MiniCRM folder name for products imported along with webshop orders. The System ID is required and should consist of digits only. Please type the correct setting. The category ID is required and should consist of digits only. Please type the correct setting. The folder name is required. Please type the correct setting. The following MiniCRM mappings are invalid: %s The following WooCommerce mappings are invalid: %s The number following "#!Project-" in the URL after opening the Webshop module in your MiniCRM account The number following "r3.minicrm.io/" in the URL after logging into your MiniCRM account. The plugin is not yet tested on Wordpress version %s. The sync hasn't finished yet. Are you sure to leave and abort it? Turning it on can help diagnose issues. It is recommended to turn it off during normal operation. WooCommerce data mapping You can also try other WooCommerce order data at your own risk (look for <code>get...()</code> methods of <a href="%s" target="_blank">WC_Order</a>.). You can generate one in your MiniCRM account's Settings > System > API key > Create new API key You can map <em>Extra Product Options</em> data to MiniCRM fields here. Write one mapping per line in the following format:
<br><code>[Product Option]:[MiniCRM field]</code>
<br>
<br>Use the label displayed to the customer as a <code>[Product Option]</code> (the one typed under <em>Label</em> on the <em>Extra Product Options</em> admin)! You can map basic WooCommerce order data to MiniCRM fields here. Write one per line in the following format:
<br><code>[WooCommerce data]:[MiniCRM field]</code>
<br>
<br>You can use the ones below as <code>[WooCommerce data]</code>: You can use the text displayed as <em>Field label on HTML forms</em> while editing the custom fields of the <em>Order</em> module for a <code>[MiniCRM field]</code>. For example if you see <em>[Project.%1$s]</em> there, then use only <em>%1$s</em>.
<br>
<br>Separate the two fields in a single mapping with <code>:</code> (colon). eg. 12345 eg. 21 eg. Webshop products Project-Id-Version: MiniCRM WooCommerce Sync plugin v1.5.3
PO-Revision-Date: 
Language-Team: Márton Tamás <marton.tamas@webhelyesarcu.hu>
Report-Msgid-Bugs-To: Translator Name <translations@example.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit
Plural-Forms: nplurals=2; plural=(n != 1);
X-Textdomain-Support: yesX-Generator: Poedit 1.6.4
X-Poedit-SourceCharset: UTF-8
X-Poedit-KeywordsList: __;_e;esc_html_e;esc_html_x:1,2c;esc_html__;esc_attr_e;esc_attr_x:1,2c;esc_attr__;_ex:1,2c;_nx:4c,1,2;_nx_noop:4c,1,2;_x:1,2c;_n:1,2;_n_noop:1,2;__ngettext:1,2;__ngettext_noop:1,2;_c,_nc:4c,1,2
X-Poedit-Basepath: ..
X-Generator: Poedit 2.0.6
Last-Translator: Márton Tamás <marton.tamas@webhelyesarcu.hu>
Language: hu
X-Poedit-SearchPath-0: .
X-Poedit-SearchPathExcluded-0: *.js
 <strong>Ha megváltoztatjuk egy termékjellemző feliratát</strong>, ajánlott megtartani a régi párosítást, és az újat is felvenni. A jellemzőket ugyanis a rendeléskori feliratukkal tárolja a bolt. <strong>Szinkronizálás</strong>... %s% Hátralévő idő: %s A befejezéshez tartsd nyitva a böngésző ablakát/lapját. API kulcs Váratlan <strong class="minicrm-woocommerce-sync-error">hiba merült fel</strong>, a szinkronizáció félbeszakadt. (Kérlek, ellenőrizd a naplót.) CategoryId Példa:
Póló felirat:PoloFelirat
Kiterjesztett garancia:KiterjesztettGarancia Példa:
shipping_postcode:SzallitasIranyitoszama
shipping_city:SzallitasTelepulese Extra Product Options mezőpárosítás %s adatlap sikeresen szinkronizálásra küldve. <strong class="minicrm-woocommerce-sync-success">Nem merült fel hiba</strong>, de a szinkronizáció tényleges sikeréről csak a <em>Sync</em> naplóból győződhetünk meg. Mappanév Teljes számlázási cím Teljes számlázási név Teljes szállítási cím Teljes szállítási név Ha több webshopot szinkronizálsz egyetlen MiniCRM fiókba, akkor minden webshopnak adj meg ebben a mezőben egyedi azonosítót. Így elkerülheted, hogy a különböző boltok egymás rendeléseit felülírják MiniCRM-ben. Ha egy MiniCRM fiókba csak egy webshopot szinkronizálsz, hagyd ezt az értéket 0-n. MiniCRM fiók nyelve A következő PHP bővítmény szükséges a bővítmény működéséhez, de a telepítéseden nem elérhető: %s. SzallitasIranyitoszama Bolt azonosító Minden rendelés szinkronizálása SystemId (felhasználói rendszer azonosító) Próba-szinkronizáció PoloFelirat Az API kulcs megadása kötelező, és annak 32 betűből ill. számból kell állnia. Kérlek, töltsd ki helyesen a beállítást. Azon mappa neve a MiniCRM-ben, ahova a tételnek be kell kerülnie. A <em>SystemId</em> megadása kötelező, és csak számjegyekből állhat. Kérlek, javítsd a beállítást. A <em>CategoryId</em> megadása kötelező, és az csak számjegyekből állhat. Kérlek, adj meg helyes beállítást. A <em>mappanév</em> kitöltése kötelező. Kérlek, javítsd a beállítást. A következő MiniCRM mezők érvénytelenek: %s A következő WooCommerce adatok érvénytelenek: %s A MiniCRM fiókod Webshop modulja megnyitásakor, az URL-ben látható "#!Project-" részt követő szám A MiniCRM fiókodba bejelentkezve, az "r3.minicrm.hu/" részt követő szám. A bővítmény helyes működését még nem ellenőriztük a Wordpress %s verzióján. A szinkronizáció még folyamatban van. Biztosan félbeszakítod, és elhagyod az oldalt? A bekapcsolása segíthet a hibák felderítésében. Helyes működés esetén ajánlott kikapcsolni. WooCommerce mezőpárosítás Saját felelősségre megpróbálhatsz egyéb WooCommerce rendelés adatokat is párosítani (keresd a <a href="%s" target="_blank">WC_Order</a> <code>get...()</code> metódusait). A MiniCRM fiókodban, a Beállítások > Rendszer > API kulcs > API kulcs újragenerálása gombbal hozhatod létre Itt párosíthatod az <em>Extra Product Options</em> termékjellemzőket a MiniCRM mezőkhöz. Soronként egyet írj az alábbi formátumban:
<br><code>[Termékjellemző]:[MiniCRM mező]</code>
<br>
<br>A <code>[Termékjellemző]</code> helyére a vevőnek megjelenő termékjellemző felirata kerüljön (amit az <em>Extra Product Options</em> admin felületén, a termékjellemző szerkesztésekor a <em>Label</em> mezőbe írunk)! Itt párosíthatjuk a WooCommerce rendelések adatait MiniCRM mezőkkel. Soronként egyet adjunk meg a következő formátumban:
<br><code>[WooCommerce adat]:[MiniCRM mező]</code>
<br>
<br>A <code>[WooCommerce adat]</code> helyére az alábbiak közül választhatunk: A <code>[MiniCRM mező]</code> helyére a Megrendelés modul egyedi mezőjének szerkesztésekor látható, <em>Mezőnév HTML űrlapokon</em> dobozban, a szögletes zárójelek között, a <em>Project.</em> részlet után megjelenő szöveg kerüljön! Pl. ha <em>[Project.%1$s]</em> szerepel a MiniCRM felületen, akkor a <em>%1$s</em> részletet másoljuk ki!
<br>
<br>A két mezőt pedig a <code>:</code> (kettőspont) írásjellel válasszuk el! pl. 12345 pl. 21 pl. Webshop termékek 