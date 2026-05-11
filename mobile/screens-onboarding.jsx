// screens-onboarding.jsx — Splash, Onboarding (4), Login, OTP

const { useState: useState_o, useEffect: useEffect_o, useRef: useRef_o } = React;

// 0. Splash
function ScreenSplash({ onNext }) {
  useEffect_o(() => {
    const t = setTimeout(onNext, 1800);
    return () => clearTimeout(t);
  }, []);
  return (
    <div data-screen-label="00 Splash" className="lc-doodle-bg" style={{ position: 'absolute', inset: 0, background: '#FFD93D', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', paddingTop: 'var(--ios-safe-top)' }} onClick={onNext}>
      <div style={{ position: 'absolute', inset: 0, opacity: 0.18, backgroundImage: 'radial-gradient(circle at 18% 22%, rgba(0,0,0,0.4) 0 2px, transparent 2px), radial-gradient(circle at 78% 65%, rgba(0,0,0,0.4) 0 2px, transparent 2px), radial-gradient(circle at 42% 88%, rgba(0,0,0,0.4) 0 2px, transparent 2px)', backgroundSize: '80px 80px' }}/>
      <div style={{ width: 110, height: 110, borderRadius: '50%', background: '#0A0A0A', display: 'flex', alignItems: 'center', justifyContent: 'center', marginBottom: 24, position: 'relative' }}>
        <I.Pepper size={56} stroke="#FF5A1F" sw={1.6}/>
      </div>
      <div style={{ fontFamily: 'var(--font-display)', textTransform: 'uppercase', textAlign: 'center', lineHeight: 0.88 }}>
        <div style={{ fontSize: 42, color: '#0A0A0A' }}>LE</div>
        <div style={{ fontSize: 56, color: '#FF5A1F', letterSpacing: '0.01em' }}>CAYENNE</div>
      </div>
      <div style={{ marginTop: 22, fontSize: 11, fontWeight: 700, letterSpacing: '0.32em', color: '#0A0A0A' }}>HÉNIN-BEAUMONT · 62210</div>
      <div style={{ position: 'absolute', bottom: 60, fontSize: 10, fontWeight: 600, letterSpacing: '0.18em', color: 'rgba(0,0,0,0.55)', textTransform: 'uppercase' }}>Du peuple, pour le peuple</div>
    </div>
  );
}

// Onboarding shell
function OnboardingShell({ index, onNext, onSkip, hero, eyebrow, title, body, accent }) {
  const total = 4;
  return (
    <div data-screen-label={`0${index+1} Onboarding`} style={{ position: 'absolute', inset: 0, background: '#FFFFFF', display: 'flex', flexDirection: 'column', paddingTop: 'var(--ios-safe-top)' }}>
      {/* top bar — logo centered, skip absolute right */}
      <div style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '14px 20px 8px', minHeight: 36 }}>
        <Logo size={14}/>
        <button onClick={onSkip} style={{ position: 'absolute', right: 20, top: '50%', transform: 'translateY(-50%)', background: 'transparent', border: 0, fontSize: 11, fontWeight: 700, color: 'var(--gray-3)', textTransform: 'uppercase', letterSpacing: '0.1em', cursor: 'pointer' }}>Passer</button>
      </div>
      {/* hero */}
      <div style={{ flex: 1, position: 'relative', overflow: 'hidden' }}>{hero}</div>
      {/* content */}
      <div style={{ padding: '24px 24px 28px', background: '#fff' }}>
        <div className="lc-eyebrow" style={{ color: accent, marginBottom: 10 }}>{eyebrow}</div>
        <h1 className="lc-display" style={{ margin: 0, fontSize: 44, color: 'var(--ink)' }}>{title}</h1>
        <p style={{ marginTop: 14, marginBottom: 24, fontSize: 15, lineHeight: 1.5, color: 'var(--gray-4)', maxWidth: 320 }}>{body}</p>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <Dots count={total} active={index} color="#0A0A0A"/>
          <button onClick={onNext} style={{ width: 64, height: 64, borderRadius: 999, background: 'var(--ink)', color: '#fff', border: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', boxShadow: '0 8px 24px rgba(0,0,0,0.18)' }}>
            <I.Arrow size={26} stroke="#FF5A1F" sw={2.4}/>
          </button>
        </div>
      </div>
    </div>
  );
}

function ScreenOnb1({ onNext, onSkip }) {
  const hero = (
    <div className="lc-doodle-bg" style={{ position: 'absolute', inset: 0, background: '#FFD93D' }}>
      <div style={{ position: 'absolute', inset: 0, opacity: 0.16, backgroundImage: 'radial-gradient(circle at 18% 22%, rgba(0,0,0,0.4) 0 2px, transparent 2px), radial-gradient(circle at 78% 65%, rgba(0,0,0,0.4) 0 2px, transparent 2px)', backgroundSize: '80px 80px' }}/>
      {/* big slanted text Pop's-style */}
      {/* [test-e2e fix A-003 round-2 2026-05-11] clamp font-size + left-align so BIENVENUE never clips */}
      <div style={{ position: 'absolute', top: 60, left: 10, right: 80, transform: 'rotate(-4deg)', opacity: 0.95 }}>
        <div style={{ fontFamily: 'var(--font-display)', fontSize: 'clamp(48px, 18vw, 78px)', color: '#FF5A1F', textTransform: 'uppercase', lineHeight: 0.85, letterSpacing: '0.01em', textShadow: '4px 4px 0 #0A0A0A', whiteSpace: 'nowrap' }}>BIENVENUE</div>
      </div>
      {/* Hero food slot */}
      <div style={{ position: 'absolute', bottom: -20, left: 24, right: 24, height: 280 }}>
        <Slot id="onb-burger" h="100%" radius={20} placeholder="Hero burger" src="assets/menu/signature/cayenne-hero.png" alt="Le Cayenne signature"/>
      </div>
      {/* corner accent — moved further up-right to avoid overlapping the BIENVENUE wordmark */}
      <div style={{ position: 'absolute', top: 8, right: 12, width: 56, height: 56, background: '#0A0A0A', borderRadius: 999, display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 2 }}>
        <Stamp size={36}/>
        <div style={{ position: 'absolute', color: '#FFD93D', fontSize: 9, fontWeight: 700, letterSpacing: '0.08em' }}>EST. 24</div>
      </div>
    </div>
  );
  return (
    <OnboardingShell
      index={0}
      hero={hero}
      onNext={onNext}
      onSkip={onSkip}
      eyebrow="01 — Bienvenue"
      title={<>Salut, c'est <span style={{ color: 'var(--orange)' }}>Le&nbsp;Cayenne</span></>}
      body="Smash burgers, tacos, bowls — fait maison à Hénin-Beaumont. Du peuple, pour le peuple."
      accent="var(--orange)"
    />
  );
}

function ScreenOnb2({ onNext, onSkip }) {
  const hero = (
    <div style={{ position: 'absolute', inset: 0, background: '#0A0A0A', overflow: 'hidden' }}>
      {/* big background number */}
      <div style={{ position: 'absolute', top: -20, left: -10, right: -10, fontFamily: 'var(--font-display)', fontSize: 280, color: '#FF5A1F', opacity: 0.95, textAlign: 'center', lineHeight: 0.85 }}>30s</div>
      <div style={{ position: 'absolute', top: 36, left: 24, fontSize: 11, color: '#FFD93D', fontWeight: 700, letterSpacing: '0.32em', textTransform: 'uppercase' }}>// Speedrun</div>
      <div style={{ position: 'absolute', bottom: 28, left: 24, right: 24, height: 260 }}>
        <Slot id="onb-tender" h="100%" radius={20} placeholder="Tenders dipped in sauce" src="assets/menu/generated_tenders-12-pieces.png" alt="Tenders croustillants"/>
      </div>
      {/* sticker callouts */}
      <div style={{ position: 'absolute', top: 200, right: 22, transform: 'rotate(8deg)', background: '#FFD93D', color: '#0A0A0A', fontFamily: 'var(--font-display)', fontSize: 18, padding: '8px 14px', borderRadius: 8, boxShadow: '4px 4px 0 #FF5A1F' }}>3 TAPS</div>
    </div>
  );
  return (
    <OnboardingShell
      index={1}
      hero={hero}
      onNext={onNext}
      onSkip={onSkip}
      eyebrow="02 — Vitesse"
      title={<>Commande en <span style={{ color: 'var(--orange)' }}>30 sec</span></>}
      body="Choisis ton plat, personnalise-le avec tes suppléments, et valide. C'est tout. Promis, on chronomètre."
      accent="var(--orange)"
    />
  );
}

function ScreenOnb3({ onNext, onSkip }) {
  const hero = (
    <div style={{ position: 'absolute', inset: 0, background: '#FF5A1F', overflow: 'hidden' }}>
      <div style={{ position: 'absolute', top: 24, left: 0, right: 0, textAlign: 'center', fontFamily: 'var(--font-display)', fontSize: 64, color: '#FFD93D', textTransform: 'uppercase', lineHeight: 0.9, letterSpacing: '0.02em' }}>VIENS<br/>RÉCUPÉRER</div>
      {/* Conveyor belt of bags */}
      <div style={{ position: 'absolute', bottom: 40, left: 0, right: 0, display: 'flex', gap: 14, justifyContent: 'center' }}>
        {[0,1,2].map(i => (
          <div key={i} style={{ width: 96, height: 130, background: '#0A0A0A', borderRadius: 8, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'flex-end', padding: 12, transform: `rotate(${(i-1)*4}deg) translateY(${i===1?-8:0}px)`, boxShadow: i===1 ? '0 12px 24px rgba(0,0,0,0.25)' : 'none' }}>
            <div style={{ width: 32, height: 4, background: '#FFD93D', marginBottom: 8 }}/>
            <div style={{ fontFamily: 'var(--font-display)', fontSize: 18, color: '#FFD93D', textAlign: 'center', lineHeight: 1 }}>LE<br/>CAYENNE</div>
            <div style={{ fontSize: 8, fontWeight: 700, color: 'rgba(255,217,61,0.5)', letterSpacing: '0.18em', marginTop: 6 }}>#1234</div>
          </div>
        ))}
      </div>
      <div style={{ position: 'absolute', bottom: 12, left: 0, right: 0, textAlign: 'center', fontSize: 10, fontWeight: 700, color: 'rgba(255,255,255,0.7)', letterSpacing: '0.2em' }}>PRÊT POUR TOI · NO QUEUE</div>
    </div>
  );
  return (
    <OnboardingShell
      index={2}
      hero={hero}
      onNext={onNext}
      onSkip={onSkip}
      eyebrow="03 — Pickup"
      title="Ta commande t'attend"
      body="Pas de file, pas d'attente. Cash ou CB sur place. Ton sac est prêt quand tu pousses la porte."
      accent="var(--orange)"
    />
  );
}

function ScreenOnb4({ onNext, onSkip }) {
  const hero = (
    <div style={{ position: 'absolute', inset: 0, background: '#0A0A0A', overflow: 'hidden' }}>
      {/* loyalty card mockup */}
      <div style={{ position: 'absolute', top: 50, left: 32, right: 32, height: 200, background: '#FFD93D', borderRadius: 16, padding: 20, transform: 'rotate(-3deg)', boxShadow: '0 20px 40px rgba(0,0,0,0.4)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <Logo size={11}/>
          <div style={{ fontFamily: 'var(--font-mono)', fontSize: 9, color: '#0A0A0A', opacity: 0.6 }}>#FK-12345</div>
        </div>
        <div style={{ fontFamily: 'var(--font-display)', fontSize: 56, color: '#0A0A0A', lineHeight: 0.9, marginTop: 22 }}>347<span style={{ fontSize: 18, color: 'var(--orange)' }}> PTS</span></div>
        <div style={{ marginTop: 8, height: 6, background: 'rgba(0,0,0,0.15)', borderRadius: 999, overflow: 'hidden' }}>
          <div style={{ width: '69%', height: '100%', background: '#FF5A1F' }}/>
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6, fontSize: 9, fontWeight: 700, color: '#0A0A0A', textTransform: 'uppercase', letterSpacing: '0.08em' }}>
          <span>FIDÉLITÉ</span>
          <span>153 PTS → BURGER OFFERT</span>
        </div>
      </div>
      {/* QR card */}
      <div style={{ position: 'absolute', bottom: 28, left: 60, right: 60, background: '#fff', borderRadius: 16, padding: 18, transform: 'rotate(2deg)', boxShadow: '0 16px 32px rgba(0,0,0,0.4)' }}>
        <QRMock size={148}/>
        <div style={{ marginTop: 10, fontSize: 9, fontWeight: 700, letterSpacing: '0.18em', textAlign: 'center', color: '#0A0A0A' }}>CARTE FIDÉLITÉ · LE CAYENNE</div>
      </div>
    </div>
  );
  return (
    <OnboardingShell
      index={3}
      hero={hero}
      onNext={onNext}
      onSkip={onSkip}
      eyebrow="04 — Fidélité"
      title={<>1€ dépensé = <span style={{ color: 'var(--orange)' }}>1 point</span></>}
      body="Tes commandes te rapportent. 500 pts = 5€ offerts, 1000 pts = burger gratuit. Présente ton QR à la caisse."
      accent="var(--orange)"
    />
  );
}

// LOGIN — phone
function ScreenLogin({ onNext, onBack }) {
  const [phone, setPhone] = useState_o('06 ');
  const valid = phone.replace(/\s/g, '').length >= 10;
  return (
    <div data-screen-label="05 Login" style={{ position: 'absolute', inset: 0, background: '#FFFFFF', display: 'flex', flexDirection: 'column', paddingTop: 'var(--ios-safe-top)' }}>
      <ScreenHeader left={<IconBtn onClick={onBack} ariaLabel="Retour"><I.Back size={20}/></IconBtn>} center={<Logo size={14}/>}/>
      <div style={{ flex: 1, padding: '12px 28px', position: 'relative' }}>
        <div className="lc-eyebrow" style={{ color: 'var(--orange)', marginBottom: 10 }}>// Étape 1 sur 2</div>
        <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.92, color: 'var(--ink)' }}>Ton<br/>numéro,<br/>chef.</h1>
        <p style={{ marginTop: 14, fontSize: 14, lineHeight: 1.55, color: 'var(--gray-4)' }}>Pas de spam, promis. On t'envoie juste un code par SMS pour confirmer que c'est bien toi.</p>
        {/* phone field */}
        <div style={{ marginTop: 28, display: 'flex', alignItems: 'center', gap: 8, height: 64, background: 'var(--ink)', borderRadius: 18, padding: '0 8px 0 16px', boxShadow: '4px 4px 0 var(--orange)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 10px', background: 'rgba(255,255,255,0.12)', borderRadius: 12 }}>
            <span style={{ fontSize: 16 }}>🇫🇷</span>
            <span style={{ color: '#fff', fontFamily: 'var(--font-mono)', fontSize: 14 }}>+33</span>
            <I.ChevronDown size={14} stroke="rgba(255,255,255,0.6)"/>
          </div>
          <input value={phone} onChange={e => setPhone(e.target.value)} placeholder="6 12 34 56 78" aria-label="Numéro de téléphone mobile français, commençant par 06 ou 07" inputMode="tel" autoComplete="tel-national" style={{ flex: 1, background: 'transparent', border: 0, outline: 'none', color: '#fff', fontSize: 19, fontFamily: 'var(--font-mono)', letterSpacing: '0.04em' }}/>
        </div>
        {/* benefits */}
        <div style={{ marginTop: 32, display: 'grid', gap: 10 }}>
          {[
            { icon: I.Sparkle, text: 'Commandes en 30 sec' },
            { icon: I.Gift, text: '+25 pts dès la 1ʳᵉ commande' },
            { icon: I.Shield, text: 'Aucune donnée revendue' },
          ].map((b, i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 14px', background: 'var(--cream)', borderRadius: 14 }}>
              <div style={{ width: 32, height: 32, borderRadius: 10, background: 'var(--ink)', color: 'var(--yellow)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <b.icon size={18}/>
              </div>
              <span style={{ fontSize: 14, fontWeight: 600, color: 'var(--ink)' }}>{b.text}</span>
            </div>
          ))}
        </div>
      </div>
      {/* Sticky CTA */}
      <div style={{ padding: '12px 24px 32px' }}>
        <button onClick={onNext} className="lc-btn" style={{ background: 'var(--ink)', color: '#fff', width: '100%', height: 60, fontSize: 14 }}>
          Recevoir le code <I.Arrow size={18} stroke="var(--orange)"/>
        </button>
        <div style={{ marginTop: 12, textAlign: 'center', fontSize: 11, color: 'var(--gray-3)' }}>En continuant, tu acceptes nos <u>CGU</u> · <u>Confidentialité</u></div>
      </div>
    </div>
  );
}

// OTP code entry
function ScreenOTP({ onNext, onBack }) {
  const [code, setCode] = useState_o(['', '', '', '']);
  const refs = [useRef_o(), useRef_o(), useRef_o(), useRef_o()];
  const set = (i, v) => {
    if (v.length > 1) v = v.slice(-1);
    if (!/^\d?$/.test(v)) return;
    const next = [...code]; next[i] = v; setCode(next);
    if (v && i < 3) refs[i+1].current?.focus();
    if (next.join('').length === 4) setTimeout(onNext, 400);
  };
  useEffect_o(() => { refs[0].current?.focus(); }, []);
  return (
    <div data-screen-label="06 OTP" style={{ position: 'absolute', inset: 0, background: '#FFFFFF', display: 'flex', flexDirection: 'column', paddingTop: 'var(--ios-safe-top)' }}>
      <ScreenHeader left={<IconBtn onClick={onBack} ariaLabel="Retour"><I.Back size={20}/></IconBtn>} center={<Logo size={14}/>}/>
      <div style={{ flex: 1, padding: '12px 28px' }}>
        <div className="lc-eyebrow" style={{ color: 'var(--orange)', marginBottom: 10 }}>// Étape 2 sur 2</div>
        <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.92 }}>Entre<br/>ton code.</h1>
        <p style={{ marginTop: 14, fontSize: 14, lineHeight: 1.55, color: 'var(--gray-4)' }}>Code envoyé au <b style={{ color: 'var(--ink)' }}>+33 6 12 34 56 78</b> · <span style={{ color: 'var(--orange)' }}>Modifier</span></p>
        {/* code boxes — A11-005 P1 round-2 2026-05-11 : fieldset+legend
            group + per-input aria-label so screen-readers can identify
            which digit position the user is on. */}
        <fieldset style={{ marginTop: 32, padding: 0, border: 0 }}>
          <legend className="lc-sr-only">Code de validation à 4 chiffres reçu par SMS</legend>
          <div style={{ display: 'flex', gap: 12, justifyContent: 'space-between' }}>
            {code.map((c, i) => (
              <input
                key={i}
                ref={refs[i]}
                value={c}
                onChange={e => set(i, e.target.value)}
                onKeyDown={e => { if (e.key === 'Backspace' && !c && i > 0) refs[i-1].current?.focus(); }}
                maxLength={1}
                inputMode="numeric"
                autoComplete={i === 0 ? 'one-time-code' : 'off'}
                aria-label={`Chiffre ${i + 1} sur 4 du code de validation`}
                style={{
                  width: 64, height: 76, borderRadius: 18,
                  background: c ? 'var(--ink)' : 'var(--cream)',
                  color: c ? 'var(--orange)' : 'var(--ink)',
                  border: c ? '0' : '2px solid transparent',
                  fontFamily: 'var(--font-display)', fontSize: 36, textAlign: 'center', outline: 'none',
                  boxShadow: c ? '4px 4px 0 var(--yellow)' : 'none',
                  transition: 'all 0.15s',
                }}
              />
            ))}
          </div>
        </fieldset>
        <div style={{ marginTop: 22, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          {/* [test-e2e fix A-001 round-2 2026-05-11] gate dev affordance — hide demo code from prod-facing users */}
          {window.LC && window.LC.isDev
            ? <div style={{ fontSize: 12, color: 'var(--gray-3)', fontFamily: 'var(--font-mono)' }}>Code de démo : 1234</div>
            : <div/>}
          <button style={{ background: 'transparent', border: 0, fontSize: 12, fontWeight: 700, color: 'var(--gray-3)', cursor: 'pointer' }}>Renvoyer (29s)</button>
        </div>
        {/* fun illustration band */}
        <div style={{ marginTop: 36, padding: 20, background: 'var(--yellow)', borderRadius: 18, display: 'flex', alignItems: 'center', gap: 14 }}>
          <div style={{ width: 48, height: 48, background: 'var(--ink)', borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
            <I.Phone size={22} stroke="var(--orange)"/>
          </div>
          <div>
            <div style={{ fontSize: 13, fontWeight: 700 }}>SMS reçu, mais pas par toi ?</div>
            <div style={{ fontSize: 12, color: 'var(--gray-4)', marginTop: 2 }}>Pas de panique. Ton numéro est verrouillé chez nous.</div>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { ScreenSplash, ScreenOnb1, ScreenOnb2, ScreenOnb3, ScreenOnb4, ScreenLogin, ScreenOTP });
