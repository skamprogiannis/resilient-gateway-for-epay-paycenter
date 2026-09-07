=== WebHosting4U Secure Card Gateway for ePay Paycenter (Piraeus Bank) — Ελληνική Τεκμηρίωση ===

Ελληνική τεκμηρίωση του προσθέτου WordPress / WooCommerce. Το επίσημο
`readme.txt` παραμένει στα Αγγλικά διότι αναλύεται από το WordPress.org
για την επίσημη σελίδα του προσθέτου. Το παρόν αρχείο συνοδεύει το
πρόσθετο μέσα στο zip και είναι διαθέσιμο σε όποιον περιηγείται στο
αποθετήριο SVN ή κατεβάζει την πηγή.

Για πλήρη ελληνική απεικόνιση της σελίδας στο WordPress.org, οι
μεταφράσεις υποβάλλονται και εγκρίνονται μέσω του
translate.wordpress.org:
https://translate.wordpress.org/projects/wp-plugins/secure-card-gateway-for-epay-paycenter-piraeus-bank/


== Περιγραφή ==

Το πρόσθετο ενσωματώνει το WooCommerce με την υπηρεσία *ePay Paycenter
Redirection* που λειτουργεί η Euronet Merchant Services για λογαριασμό
της Τράπεζας Πειραιώς. Υλοποιεί πλήρως την επίσημη προδιαγραφή
*Redirection v3.1* από άκρο σε άκρο:

* SOAP Ticketing Web Service (`IssueNewTicket`) με κωδικοποίηση UTF-8
  για την έκδοση μοναδικού `TranTicket` ανά παραγγελία.
* Αυτόματη υποβολή φόρμας POST προς τη σελίδα ασφαλούς πληρωμής της
  τράπεζας (`pay.aspx`), ώστε τα δεδομένα κάρτας να μην περνούν ποτέ
  από τον διακομιστή σας.
* Πλήρης επαλήθευση HMAC-SHA256 HashKey πριν χαρακτηριστεί η
  παραγγελία ως εξοφλημένη — μη έγκυρο HashKey σημαίνει «Σε αναμονή»
  και καταγραφή στα logs.
* Συναλλαγές *Sale* — εκκαθαρίζονται στην επόμενη παρτίδα, χωρίς
  περαιτέρω ενέργεια από τον έμπορο.
* **Υποστήριξη πληρωμών IRIS** (άμεσες πληρωμές μέσω του δικτύου ΔΙΑΣ).
  Όταν η Euronet Merchant Services ενεργοποιήσει το IRIS στη σύμβασή
  σας, η σελίδα πληρωμής της τράπεζας εμφανίζει στον πελάτη και τις
  δύο επιλογές (κάρτα ή IRIS). Το πρόσθετο αναγνωρίζει τις απαντήσεις
  IRIS μέσω των πεδίων `CardType=15` και `PaymentMethod=IRIS` που
  επιστρέφει η τράπεζα (πάντα μετά από επαλήθευση HMAC-SHA256), τις
  αποθηκεύει στην παραγγελία, και εμφανίζει μηνύματα προσαρμοσμένα στα
  IRIS-only σενάρια (ResponseCode 05 ακύρωση από εφαρμογή τράπεζας,
  06 τεχνικό σφάλμα, 09 εκκρεμής, 68 λήξη QR 5 λεπτών, 70 σφάλμα
  υπηρεσίας IRIS). Δεν χρειάζεται κάποια ρύθμιση από εσάς —
  η ενεργοποίηση γίνεται από την τράπεζα.

  **Προσοχή:** σύμφωνα με την πολιτική της Τράπεζας Πειραιώς, οι
  πληρωμές IRIS **δεν υποστηρίζουν δόσεις** (χρεώνεται όλο το ποσό)
  και **δεν υποστηρίζουν επιστροφές χρημάτων** (ResponseCode 9167).
* **Πληρωμές Google Pay™** (Redirection v3.1). Γίνονται δεκτές αυτόματα,
  χωρίς καμία ρύθμιση: η σελίδα της τράπεζας εμφανίζει το Google Pay σε
  συμβατές συσκευές/browsers για συναλλαγές αγοράς και προέγκρισης (με ή
  χωρίς δόσεις). Επειδή το Google Pay επιστρέφει την τυπική απάντηση
  κάρτας, επαληθεύεται με το ίδιο HashKey HMAC-SHA256· το πρόσθετο
  καταγράφει επιπλέον το πεδίο `PanCardType` (FPAN πραγματικός αριθμός
  κάρτας / DPAN token συσκευής) στην παραγγελία, ώστε να ξεχωρίζουν οι
  πληρωμές μέσω wallet.
* Πεδία 3-D Secure (όνομα, email, διεύθυνση χρέωσης / αποστολής,
  ταχυδρομικός κώδικας, κωδικός χώρας ISO 3166, αριθμός κινητού στη
  μορφή `CC-Number`) τροφοδοτούνται από τα δεδομένα της παραγγελίας.
* Συμβατότητα με WooCommerce HPOS (Custom Order Tables) και
  WooCommerce Blocks Checkout.
* Διαγνωστικά για ανίχνευση μπλοκαρίσματος των callbacks της τράπεζας
  από host-WAF (cPFence, ModSecurity / OWASP CRS, Imunify360,
  BitNinja, LiteSpeed) πριν τη μετάβαση σε παραγωγή.
* Αυτόματη ανίχνευση Cloudflare και υπόδειξη των IPv4 ranges που
  πρέπει να επιτραπούν από την τράπεζα.

**Απαιτείται συμβόλαιο αποδοχής** με την Euronet Merchant Services /
Τράπεζα Πειραιώς. Πρέπει να διαθέτετε τα διαπιστευτήρια `AcquirerId`,
`MerchantId`, `PosId`, `Username`, `Password`. Το πρόσθετο δεν
παρέχει δικούς του δοκιμαστικούς λογαριασμούς — επικοινωνήστε με την
Euronet Merchant Services για να σας χορηγηθεί δοκιμαστικός
λογαριασμός.


== Δήλωση μη συσχέτισης και εμπορικών σημάτων ==

Το παρόν πρόσθετο είναι ανεξάρτητο λογισμικό που δημοσιεύεται από τη
**WebHosting4U** και **δεν είναι** συνδεδεμένο, εγκεκριμένο,
υποστηριζόμενο ή με οποιονδήποτε άλλο επίσημο τρόπο συσχετιζόμενο με
την Τράπεζα Πειραιώς Α.Ε., την Euronet Merchant Services ή την
Automattic Inc. Τα ονόματα «ePay», «Paycenter», «Piraeus Bank» και
«WooCommerce» είναι εμπορικά σήματα των αντίστοιχων δικαιούχων και
χρησιμοποιούνται καλόπιστα, μετά τον δείκτη μη συσχέτισης «for»,
αποκλειστικά για την περιγραφή της υπηρεσίας με την οποία διασυνδέεται
το πρόσθετο, σύμφωνα με τις *WordPress.org Detailed Plugin Guidelines*
για εμπορικά σήματα τρίτων.

Η εικόνα αποδεκτών καρτών (`assets/img/wp-cards.png`) περιλαμβάνεται με
την άδεια του δικαιούχου, εντός του πλαισίου διανομής του προσθέτου
στους εμπόρους.


== Παρακολούθηση χρηστών και συγκατάθεση ==

Το πρόσθετο **δεν** φορτώνει κανέναν κώδικα analytics, telemetry,
διαφήμισης, fingerprinting, profiling ή behavioural tracking, ούτε στο
κατάστημα ούτε στο διαχειριστικό του WordPress. Δεν τοποθετεί cookies
στους περιηγητές των επισκεπτών, δεν επικοινωνεί με κανένα analytics
endpoint, και δεν συλλέγει στατιστικά χρήσης από την εγκατάσταση του
καταστηματάρχη. Η μοναδική εξερχόμενη κίνηση που παράγει είναι αυστηρά
συναλλακτική και αποκλειστικά προς τα τελικά σημεία που τεκμηριώνονται
παρακάτω.


== Εξωτερικές υπηρεσίες ==

Το πρόσθετο επικοινωνεί με τρεις εξωτερικές υπηρεσίες. Δύο
λειτουργούνται από την Euronet Merchant Services για λογαριασμό της
Τράπεζας Πειραιώς (απαραίτητες για την λειτουργία του προσθέτου). Η
τρίτη είναι δημόσιο endpoint της Cloudflare που χρησιμοποιείται μόνο
στο διαχειριστικό για να βοηθήσει στη ρύθμιση firewall για τα
callbacks της πληρωμής.

1. **ePay Paycenter Ticketing Web Service**
   * Endpoint: `https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx`
   * Τι αποστέλλεται: διαπιστευτήρια εμπόρου (AcquirerId, MerchantId,
     PosId, Username και MD5 hash του Password), MerchantReference,
     ποσό συναλλαγής, ISO 4217 currency code, τύπος αίτησης
     (πάντα Sale), και πεδία 3-D Secure από τη
     διεύθυνση χρέωσης / αποστολής του WooCommerce. **Καμία PAN ή
     CVV ή ημερομηνία λήξης δεν αποστέλλεται.**
   * Πότε: μία φορά ανά υποβολή checkout.

2. **ePay Paycenter Redirection page**
   * Endpoint: `https://paycenter.piraeusbank.gr/redirection/pay.aspx`
   * Τι αποστέλλεται: αναγνωριστικά εμπόρου, κωδικός γλώσσας,
     `MerchantReference`, και `ParamBackLink` ώστε το κουμπί
     «Ακύρωση» στη σελίδα της τράπεζας να επιστρέφει τον πελάτη
     στο σωστό WordPress URL. Το `TranTicket` διαβάζεται από τον
     περιηγητή του πελάτη ως κρυφό πεδίο φόρμας — δεν αποστέλλεται
     από τον διακομιστή του καταστήματος.
   * Πότε: μία φορά ανά checkout, αμέσως μετά την έκδοση εισιτηρίου.
   * Εισερχόμενο αντίστοιχο: η Paycenter στέλνει υπογεγραμμένη
     απάντηση (HMAC-SHA256 HashKey) πίσω στο WC-API callback URL του
     καταστήματος (`https://<site>/?wc-api=epay_paycenter`).

3. **Cloudflare IPv4 list**
   * Endpoint: `https://www.cloudflare.com/ips-v4/`
   * Τι αποστέλλεται: μόνο ένα HTTP GET χωρίς προσωπικά δεδομένα,
     χωρίς cookies, χωρίς διαπιστευτήρια — μόνο το User-Agent string
     του προσθέτου.
   * Πότε: αποκλειστικά όταν διαχειριστής ανοίγει τη σελίδα
     ρυθμίσεων της πύλης και ανιχνεύεται Cloudflare στο εισερχόμενο
     request. Cache για 12 ώρες σε WordPress transient.

**Όροι και Πολιτικές Απορρήτου των τρίτων:**

* Euronet Merchant Services (epay): https://www.epaygreece.gr/oroi-xrisis/
* Euronet Merchant Services Privacy: https://www.epaygreece.gr/politiki-aporritou/
* Τράπεζα Πειραιώς Privacy Policy: https://www.piraeusbank.gr/en/idiwtes/protection-of-personal-data
* Cloudflare Privacy: https://www.cloudflare.com/privacypolicy/
* Cloudflare Terms: https://www.cloudflare.com/terms/

Πριν ενεργοποιήσετε την πύλη, ο έμπορος οφείλει να εξετάσει και να
αποδεχθεί τους όρους χρήσης και την πολιτική απορρήτου της Euronet
Merchant Services / Τράπεζας Πειραιώς και να ενημερώσει αναλόγως τη
δική του πολιτική απορρήτου.


== Εγκατάσταση ==

1. Ανεβάστε το zip του προσθέτου μέσω **Πρόσθετα → Προσθήκη νέου →
   Μεταφόρτωση προσθέτου**, ή αποσυμπιέστε στον φάκελο
   `wp-content/plugins/secure-card-gateway-for-epay-paycenter-piraeus-bank/`.
2. Ενεργοποιήστε το πρόσθετο.
3. Μεταβείτε στο **WooCommerce → Ρυθμίσεις → Πληρωμές** και
   ενεργοποιήστε την *ePay Paycenter (Τράπεζα Πειραιώς)*.
4. Εισάγετε τα AcquirerId, MerchantId, PosId, Username, Password
   ακριβώς όπως σας τα έδωσε η Euronet Merchant Services.
5. Ρυθμίστε το περιβάλλον (Test / Live), τη γλώσσα και τον τύπο
   συναλλαγής.
6. Δώστε στην Euronet Merchant Services τα ακόλουθα URLs για την
   εγγραφή σας:
     * Referrer URL: η σελίδα checkout του καταστήματος.
     * Success URL: `https://το-site-σας.gr/wc-api/epay_paycenter/`
     * Failure URL: `https://το-site-σας.gr/wc-api/epay_paycenter/`
     * Backlink URL: `https://το-site-σας.gr/wc-api/epay_paycenter/`
     * IP address: το εξερχόμενο IP του διακομιστή σας.
     * Response method: **POST** (συνιστάται).
7. Εκτελέστε τα υποχρεωτικά δοκιμαστικά σενάρια της Ενότητας 7 του
   εγχειριδίου Redirection v3.1 πριν ζητήσετε live διαπιστευτήρια.

Όλα τα URLs εμφανίζονται έτοιμα για αντιγραφή στη σελίδα ρυθμίσεων
του προσθέτου, στην ενότητα «Στοιχεία σύνδεσης με την τράπεζα».


== Συχνές Ερωτήσεις ==

= Ποιες κάρτες υποστηρίζονται; =

Visa, Mastercard, Maestro, και (κατόπιν συμφωνίας με την Euronet
Merchant Services) Diners / Discover και American Express.

= Αποθηκεύει το πρόσθετο δεδομένα κάρτας; =

Όχι. Τα δεδομένα κάρτας καταχωρούνται αποκλειστικά στη σελίδα
ασφαλούς πληρωμής της Paycenter και δεν περνούν ποτέ από τον
διακομιστή σας. Το πρόσθετο αποθηκεύει μόνο μη ευαίσθητα μεταδεδομένα
όπως approval code, response code και `SupportReferenceID` για
συμφωνία λογαριασμών.

= Τι γίνεται αν η επαλήθευση HashKey αποτύχει; =

Η παραγγελία τίθεται σε *Σε αναμονή* και καταγράφεται σχετική ειδοποίηση.
Η επαλήθευση είναι υποχρεωτική πριν χαρακτηριστεί μια παραγγελία ως
εξοφλημένη — λανθασμένο HashKey αντιμετωπίζεται ως ενδεχομένως
παραποιημένο callback.

= Είναι συμβατό με GDPR; =

Το πρόσθετο μεταδίδει μόνο τα ελάχιστα δεδομένα που απαιτούνται για τη
συναλλαγή (σύνολο παραγγελίας, νόμισμα, αναφορά εμπόρου και πεδία 3-D
Secure όπως email και διεύθυνση χρέωσης). Δεν συλλέγονται analytics
ή telemetry.

= Πώς μπορώ να αναφέρω πρόβλημα ή να ζητήσω υποστήριξη; =

Μέσω της σελίδας υποστήριξης του προσθέτου στο WordPress.org:
https://wordpress.org/support/plugin/secure-card-gateway-for-epay-paycenter-piraeus-bank/

= Πού αναφέρω σφάλματα ασφάλειας (security bugs) του προσθέτου; =

Παρακαλούμε αναφέρετε σφάλματα ασφαλείας που εντοπίζετε στον πηγαίο
κώδικα του προσθέτου μέσω του [Patchstack Vulnerability Disclosure
Program](https://patchstack.com/database/vdp/cfd86dd3-29dd-411e-b5d7-731c76b719f0).
Η ομάδα της Patchstack θα βοηθήσει με την επαλήθευση, την ανάθεση CVE,
και θα ενημερώσει τους προγραμματιστές του προσθέτου.


== Συμβατότητα με WAF ==

Μερικά WAF κοινής φιλοξενίας (cPFence, ModSecurity / OWASP CRS,
Imunify360, BitNinja, LiteSpeed WAF) μπορεί να μπλοκάρουν τα callbacks
αποτυχημένων συναλλαγών της Paycenter επειδή το payload τους περιέχει
μοτίβα (κενό HashKey, ελληνικά μηνύματα σφάλματος) που μοιάζουν με
επιθετικά signatures. Στη σελίδα ρυθμίσεων του προσθέτου υπάρχει
διαγνωστικό «Διαγνωστικά callback» που εκτελεί realistic
αποτυχημένο POST loopback στο callback URL του προσθέτου και αναφέρει
αν μπλοκάρεται πριν φτάσει στην PHP. Καμία παραγγελία δεν
δημιουργείται ή τροποποιείται — το payload φέρει `WAFTEST-`
merchant reference που δεν αντιστοιχεί σε καμία πραγματική παραγγελία.


== Άδεια χρήσης ==

GPL-2.0-or-later. Δείτε το αρχείο `LICENSE.txt` που συνοδεύει το
πρόσθετο.


== Δείτε επίσης ==

* Επίσημη σελίδα στο WordPress.org:
  https://wordpress.org/plugins/secure-card-gateway-for-epay-paycenter-piraeus-bank/
* Αγγλικό readme: `readme.txt` (αναλύεται από το wordpress.org).
* Μεταφράσεις WordPress.org:
  https://translate.wordpress.org/projects/wp-plugins/secure-card-gateway-for-epay-paycenter-piraeus-bank/
