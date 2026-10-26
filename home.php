<!-- home.php -->
<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css?v=<?php echo time(); ?>">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
   <!-- Bootstrap CSS -->
   <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
   
   <!-- Bootstrap JS -->
   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
   
   <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

   <link rel="icon" href="images/MLW logo.png" type="image/png">
   <title>ML Bills Payment</title>
   <style>
      * {
         margin: 0;
         padding: 0;
         box-sizing: border-box;
      }

      /* layout: make page stretch to full viewport so footer can sit at bottom */
      html,
      body {
         height: 100%;
      }

      body {
         display: flex;
         flex-direction: column;
         min-height: 100vh;
         font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
         line-height: 1.6;
         color: #333;
         overflow-x: hidden;
         /* keep horizontal overflow hidden always */
      }

      /* allow main to grow so footer is pushed to bottom when content is short */
      main {
         flex: 1 0 auto;
      }

      /* --- Scrolling behavior:
             Disable page scrolling on desktop (default responsive).
             Enable scrolling on tablet/mobile where the original responsive behavior applies.
        */
      /* Desktop and large screens: prevent scroll (full-viewport fixed layout) */
      @media (min-width: 769px) {

         html,
         body {
            overflow: hidden;
         }
      }

      /* Tablet and mobile: allow vertical scrolling and smooth touch scrolling */
      @media (max-width: 768px) {

         html,
         body {
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
         }
      }

      /* Header */
      header {
         background: #fff;
         padding: 1rem 0;
         box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
         position: fixed;
         width: 100%;
         top: 0;
         z-index: 1000;
         transition: all 0.3s ease;
      }

      /* Responsive footer — fixed on desktop so template shows it; static on small screens */
      .footer {
         background: #fff;
         border-top: 1px solid rgba(0, 0, 0, 0.06);
         padding: 12px 0;
         width: 100%;
         box-sizing: border-box;
         position: fixed;
         left: 0;
         right: 0;
         bottom: 0;
         z-index: 999;
         /* below header (1000) but above page content */
         backdrop-filter: blur(4px);
         /* subtle effect when footer overlays content */
      }

      /* reserve space so main content isn't hidden under the fixed footer */
      main {
         padding-bottom: 72px;
         /* adjust if footer height changes */
      }

      .footer-container {
         max-width: 1200px;
         margin: 0 auto;
         padding: 0 20px;
         display: flex;
         align-items: center;
         justify-content: center;
         gap: 12px;
         flex-wrap: wrap;
         text-align: center;
      }

      .footer p {
         font-size: 14px;
         font-weight: 500;
         opacity: 0.9;
         margin: 0;
         color: #666;
      }

      /* On smaller displays use normal document flow so footer behaves like a regular element */
      @media (max-width: 768px) {
         .footer {
            position: static;
            bottom: auto;
            padding: 14px 0;
         }

         main {
            padding-bottom: 0;
         }
      }

      .container {
         max-width: 1200px;
         margin: 0 auto;
         padding: 0 20px;
      }

      nav {
         display: flex;
         justify-content: space-between;
         align-items: center;
      }

      .logo {
         display: flex;
         align-items: center;
         font-size: 1.5rem;
         font-weight: bold;
         color: #333;
      }

      .logo-icon {
         width: 40px;
         height: 40px;
         background: linear-gradient(135deg, #e74c3c, #f39c12);
         border-radius: 50%;
         display: flex;
         align-items: center;
         justify-content: center;
         color: white;
         margin-right: 10px;
         font-weight: bold;
      }

      .nav-links {
         display: flex;
         list-style: none;
         gap: 2rem;
      }

      .nav-links a {
         text-decoration: none;
         color: #666;
         transition: color 0.3s ease;
         font-weight: 500;
      }

      .nav-links a:hover {
         color: #e74c3c;
      }

      .mobile-menu {
         display: none;
         background: none;
         border: none;
         font-size: 1.5rem;
         cursor: pointer;
      }

      /* Hero Section */
      .hero {
         padding: 120px 0 80px;
         /* background: linear-gradient(135deg, #f8f9ff 0%, #fff5f5 100%); */
         min-height: 100vh;
         display: flex;
         align-items: center;
      }

      .hero-content {
         display: grid;
         grid-template-columns: 1fr 1fr;
         gap: 4rem;
         align-items: center;
      }

      .hero-text h1 {
         font-size: 3.5rem;
         font-weight: 700;
         color: #2c3e50;
         margin-bottom: 1.5rem;
         line-height: 1.2;
      }

      .hero-text p {
         font-size: 1.1rem;
         color: #666;
         margin-bottom: 2rem;
         line-height: 1.6;
      }

      .cta-button {
         background: linear-gradient(135deg, #e74c3c, #c0392b);
         color: white;
         padding: 15px 30px;
         border: none;
         border-radius: 50px;
         font-size: 1.1rem;
         font-weight: 600;
         cursor: pointer;
         transition: all 0.3s ease;
         text-decoration: none;
         display: inline-block;
         box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
      }

      .cta-button:hover {
         transform: translateY(-2px);
         box-shadow: 0 6px 20px rgba(231, 76, 60, 0.4);
      }

      /* Hero Illustration */
      .hero-visual {
         position: relative;
         display: flex;
         justify-content: center;
         align-items: center;
      }

      /* Animations */
      @keyframes float {
         0%,
         100% {
            transform: translateY(0px);
         }
         50% {
            transform: translateY(-10px);
         }
      }

      /* Responsive Design */
      @media (max-width: 768px) {
         .nav-links {
            display: none;
         }

         .mobile-menu {
            display: block;
         }

         .hero-content {
            grid-template-columns: 1fr;
            gap: 2rem;
            text-align: center;
         }

         .hero-text h1 {
            font-size: 2.5rem;
         }
      }

      /* Scroll Effects */
      .fade-in {
         opacity: 0;
         transform: translateY(30px);
         transition: all 0.6s ease;
      }

      .fade-in.visible {
         opacity: 1;
         transform: translateY(0);
      }

      /* Make GIF background blend with the page.
            - For GIFs with a white background use mix-blend-mode: multiply;
            - For GIFs with a black/dark background use mix-blend-mode: screen;
        */
      .gif-no-bg {
         display: block;
         unicode-bidi: isolate;
         max-width: 100%;
         mix-blend-mode: multiply;
         /* switch to 'screen' if the GIF bg is dark/black */
         filter: contrast(1.03) saturate(1.02);
         /* optional: reduces white halo */
         will-change: transform;
         background: transparent;
         background-blend-mode: screen;
         pointer-events: none;
      }
   </style>
</head>

<body>
   <header id="header">
      <nav class="container">
         <div class="logo">
            <img src="images/ml.png" alt="Logo" style="height:30px;">
         </div>
         <ul class="nav-links">
            <?php include 'version-details/version-details-link.php'; ?>
         </ul>
         <button class="mobile-menu">☰</button>
      </nav>
   </header>

   <div class="header">
      <main>
         <section class="hero" id="home">
            <div class="container">
               <div class="hero-content">
                  <div class="hero-text fade-in">
                     <h1>ML Bills Payment</h1>
                     <h2>Billing, Collection and Settlement System</h2>
                     <p>A structured platform that facilitates accurate billing, efficient collection, and timely settlement of financial transactions between parties.</p>
                     <a href="login_form.php?login=1" class="cta-button">Login</a>
                  </div>
                  <div class="hero-visual fade-in">
                     <!-- Uncommented GIF here -->
                     <img src="images/billspayment_bg.gif" alt="Bills Payment Background" class="gif-no-bg">
                  </div>
               </div>
            </div>
         </section>
      </main>
   </div>
   
   <?php include 'version-details/version-details-modal.php'; ?>

   <footer class="footer">
      <div class="footer-container">
         <p><b>© <?php echo date('Y');?> All Rights Reserved | M Lhuillier</b></p>
      </div>
   </footer>

   <script>
      // Smooth scrolling for navigation links
      document.querySelectorAll('a[href^="#"]').forEach(anchor => {
         anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
               target.scrollIntoView({
                  behavior: 'smooth',
                  block: 'start'
               });
            }
         });
      });

      // Header scroll effect
      window.addEventListener('scroll', function() {
         const header = document.getElementById('header');
         if (window.scrollY > 100) {
            header.style.background = 'rgba(255, 255, 255, 0.95)';
            header.style.backdropFilter = 'blur(10px)';
         } else {
            header.style.background = '#fff';
            header.style.backdropFilter = 'none';
         }
      });

      // Fade in animation on scroll
      const observerOptions = {
         threshold: 0.1,
         rootMargin: '0px 0px -50px 0px'
      };

      const observer = new IntersectionObserver(function(entries) {
         entries.forEach(entry => {
            if (entry.isIntersecting) {
               entry.target.classList.add('visible');
            }
         });
      }, observerOptions);

      document.querySelectorAll('.fade-in').forEach(el => {
         observer.observe(el);
      });

      // Mobile menu toggle (placeholder)
      document.querySelector('.mobile-menu').addEventListener('click', function() {
         alert('Mobile menu would toggle here in a full implementation');
      });

      // Version Details Modal - Hide red dot only when modal is opened
      document.addEventListener('DOMContentLoaded', function() {
         const versionModal = document.getElementById('versionModal');
         const redDot = document.querySelector('.version-details .position-absolute.bg-danger');
         
         // Hide dot only when modal is opened (not on page load)
         if (versionModal) {
            versionModal.addEventListener('shown.bs.modal', function() {
               if (redDot) {
                  redDot.style.display = 'none';
               }
            });
         }
      });
   </script>
   
</body>

</html>