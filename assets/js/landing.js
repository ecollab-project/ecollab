  // ── Particles ──
  const container = document.getElementById('particles');
  for (let i = 0; i < 30; i++) {
    const p = document.createElement('div');
    p.className = 'particle';
    const x = Math.random() * 100;
    const size = Math.random() * 3 + 1;
    const dur = Math.random() * 15 + 10;
    const delay = Math.random() * 15;
    const color = Math.random() > 0.5 ? '#FF2D75' : '#9F3BFF';
    p.style.cssText = `left:${x}%;width:${size}px;height:${size}px;background:${color};animation-duration:${dur}s;animation-delay:${delay}s`;
    container.appendChild(p);
  }

  // ── Smooth Scroll with ripple flash on target section ──
  function flashSection(target) {
    const flash = document.createElement('div');
    flash.style.cssText = `
      position:fixed;inset:0;pointer-events:none;z-index:9999;
      background:radial-gradient(ellipse 60% 30% at 50% 50%, rgba(255,45,117,0.12) 0%, transparent 70%);
      opacity:0;transition:opacity 0.25s ease;
    `;
    document.body.appendChild(flash);
    requestAnimationFrame(() => {
      flash.style.opacity = '1';
      setTimeout(() => {
        flash.style.opacity = '0';
        setTimeout(() => flash.remove(), 300);
      }, 200);
    });

    // Section border pulse
    target.style.transition = 'box-shadow 0.4s ease';
    target.style.boxShadow = '0 0 0 2px rgba(255,45,117,0.5), 0 0 40px rgba(255,45,117,0.2)';
    target.style.borderRadius = '16px';
    setTimeout(() => {
      target.style.boxShadow = '';
      target.style.borderRadius = '';
    }, 1200);
  }

  // Ripple effect on nav link click
  function createRipple(e, el) {
    const rect = el.getBoundingClientRect();
    const ripple = document.createElement('span');
    ripple.style.cssText = `
      position:absolute;border-radius:50%;
      width:6px;height:6px;
      background:rgba(255,45,117,0.7);
      left:${e.clientX - rect.left - 3}px;
      top:${e.clientY - rect.top - 3}px;
      transform:scale(0);pointer-events:none;
      transition:transform 0.5s ease, opacity 0.5s ease;
      opacity:1;
    `;
    el.style.position = 'relative';
    el.style.overflow = 'hidden';
    el.appendChild(ripple);
    requestAnimationFrame(() => {
      ripple.style.transform = 'scale(30)';
      ripple.style.opacity = '0';
    });
    setTimeout(() => ripple.remove(), 600);
  }

  // Attach click handlers to all nav links with href targets
  document.querySelectorAll('.nav-links a[href^="#"]').forEach(link => {
    link.addEventListener('click', function(e) {
      const href = this.getAttribute('href');
      if (href === '#') return;
      e.preventDefault();

      createRipple(e, this);

      const target = document.querySelector(href);
      if (!target) return;

      const navH = document.querySelector('nav').offsetHeight;
      const top = target.getBoundingClientRect().top + window.scrollY - navH - 12;

      window.scrollTo({ top, behavior: 'smooth' });

      // Flash the target section after scroll lands
      setTimeout(() => flashSection(target), 500);

      // Update active state
      setActive(this);
    });
  });

  function setActive(el) {
    document.querySelectorAll('.nav-links a').forEach(a => a.classList.remove('nav-active'));
    el.classList.add('nav-active');
  }

  // ── Active nav link on scroll ──
  const sections = [
    { id: 'hero',         link: 'a[href="#hero"]' },
    { id: 'features',     link: 'a[href="#features"]' },
    { id: 'about',        link: 'a[href="#about"]' },
    { id: 'how-it-works', link: 'a[href="#how-it-works"]' },
    { id: 'pricing',      link: 'a[href="#pricing"]' },
    { id: 'testimonials', link: 'a[href="#testimonials"]' },
  ];

  const navH = document.querySelector('nav').offsetHeight;

  window.addEventListener('scroll', () => {
    const scrollY = window.scrollY + navH + 60;
    let current = sections[0];
    for (const sec of sections) {
      const el = document.getElementById(sec.id);
      if (el && el.offsetTop <= scrollY) current = sec;
    }
    document.querySelectorAll('.nav-links a').forEach(a => a.classList.remove('nav-active'));
    const activeLink = document.querySelector(`.nav-links ${current.link}`);
    if (activeLink) activeLink.classList.add('nav-active');
  }, { passive: true });

  // ── Intersection observer for card fade-in ──
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry, i) => {
      if (entry.isIntersecting) {
        setTimeout(() => {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
        }, (entry.target.dataset.delay || 0));
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12 });

  document.querySelectorAll('.how-card, .feat-card, .test-card, .price-card').forEach((el, i) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(30px)';
    el.style.transition = 'opacity 0.55s ease, transform 0.55s ease, box-shadow 0.3s ease';
    el.dataset.delay = (i % 4) * 80;
    observer.observe(el);
  });
</script>
