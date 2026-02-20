const localImages = {
    "assets/profile": ["LINE_ALBUM_KMCH 172.2kW_260128_253.jpg"],
    "assets/logo_use": ["1.sungrow.jpeg", "2.longi.jpeg", "3.trina.png", "4.phelpsdodge.jpeg", "5.Carrier.png", "6.byd.png", "7.solis.jpg", "8.antai.png", "9.ABB.png"],
    "assets/portfolio_sup": ["3DC06DF4-D143-4A15-B02B-A6EB13D126A0-768x768.jpeg", "74EBB765-FFAF-4AF7-A194-E650FBB9B551-768x768.jpeg", "IMG_1154-768x768.jpeg", "K.Yo_-3-768x768.png", "K.Yo_.png-768x768.jpeg", "PV-Panel-6-768x768.png"],
    "assets/Partners": ["K.Yo_-1-768x679.jpeg", "K.Yo_-2-768x768.png", "PV-Panel-7-768x768.png", "PV-Panel-8-768x768.png", "PV-Panel.png-1-768x671.jpeg", "PV-Panel.png-2-768x687.jpeg"],
    "assets/products": [
        "SG5.0RS.png",
        "SG10RT-P2.png",
        "SBS050.png",
        "SP600S.png",
        "ST255CS-2H.png",
        "ST510CS-4H.png",
        "S450S-L S1000S-L S2000S-L.png",
        "SR20D-M.png"
    ],
    "assets/longi": ["Hi-MO X10.jpeg", "Hi-MO 7.jpeg"],
    "assets/trina": ["TSM-NEG19RC.20 610-635W.jpg", "TSM-NEG21C.0 700-725W.jpg"],
    "assets/portfolio/home": ["10.jpeg", "12.jpeg", "15.png", "18.jpeg", "19.jpeg", "2.jpeg", "22.png", "3.jpeg", "31.jpeg", "32.jpeg", "33.jpeg", "34.jpeg", "35.jpeg", "42.jpg", "43.jpg", "44.jpg", "45.jpg", "46.jpg", "47.jpg", "48.jpg", "49.jpg", "5.jpeg", "7.jpeg", "8.jpeg"],
    "assets/portfolio/factory": ["1.jpg", "11.jpeg", "13.jpeg", "14.jpeg", "16.png", "17.jpeg", "20.jpeg", "21.jpeg", "23.jpeg", "24.png", "25.png", "26.png", "27.jpeg", "28.jpeg", "29.jpeg", "30.jpeg", "36.jpeg", "37.jpeg", "38.jpeg", "39.jpeg", "4.jpeg", "40.jpg", "41.jpg", "6.jpeg", "9.jpeg"]
};

// Available PDF datasheets (filenames without extension must match product name/filename)
const localPDFs = [
    "Hi-MO 7.pdf",
    "Hi-MO X10.pdf",
    "S450S-L S1000S-L S2000S-L.pdf",
    "SBS050.pdf",
    "SG10RT-P2.pdf",
    "SG5.0RS.pdf",
    "SP600S.pdf",
    "SR20D-M.pdf",
    "ST255CS-2H.pdf",
    "ST510CS-4H.pdf",
    "TSM-NEG19RC.20 610-635W.pdf",
    "TSM-NEG21C.0 700-725W.pdf"
];

// Helper function to load local images
function loadLocalImages(path, container, processFn) {
    if (localImages[path]) {
        const images = localImages[path].map(name => ({
            name: name,
            download_url: `${path}/${name}`
        }));
        processFn(images, container);
    } else {
        console.error(`Local images not found for path: ${path}`);
    }
}

document.addEventListener("DOMContentLoaded", () => {
    // Hero Slider
    const sliderContainer = document.querySelector('.hero-slider');
    if (sliderContainer) {
        const heroContent = document.querySelector('.hero-content');
        loadLocalImages("assets/profile", sliderContainer, (images, container) => {
            images.forEach((file, index) => {
                const slide = document.createElement('div');
                slide.classList.add('slide');
                if (index === 0) slide.classList.add('active');
                slide.style.backgroundImage = `url('${file.download_url}')`;
                container.insertBefore(slide, heroContent);
            });

            let slides = document.querySelectorAll(".hero-slider .slide");
            if (slides.length > 1) {
                let currentIndex = 0;
                setInterval(() => {
                    slides[currentIndex].classList.remove("active");
                    currentIndex = (currentIndex + 1) % slides.length;
                    slides[currentIndex].classList.add("active");
                }, 4000);
            }
        });
    }

    // Partners Logos
    const logosContainer = document.getElementById('partners-logos');
    if (logosContainer) {
        loadLocalImages("assets/logo_use", logosContainer, (images, container) => {
            const fragment = document.createDocumentFragment();
            [...images, ...images].forEach(file => {
                const img = document.createElement("img");
                img.src = file.download_url;
                img.alt = file.name.split('.')[0];
                fragment.appendChild(img);
            });
            container.appendChild(fragment);

            setInterval(() => {
                const firstItem = container.firstElementChild;
                if (!firstItem) return;
                const itemWidth = firstItem.offsetWidth;
                const gap = parseInt(window.getComputedStyle(container).gap) || 0;
                container.style.transition = "transform 0.5s ease-in-out";
                container.style.transform = `translateX(-${itemWidth + gap}px)`;
                container.addEventListener('transitionend', () => {
                    container.style.transition = "none";
                    container.style.transform = "translateX(0)";
                    container.appendChild(firstItem);
                }, { once: true });
            }, 3000);
        });
    }

    // Stats Section Counter Animation
    const statsSection = document.querySelector(".stats-section");
    if (statsSection) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounters();
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });
        observer.observe(statsSection);
    }

    // Load Shared Components (Footer, Buttons, Modals, Navbar)
    loadSharedComponents();

    // Initial language update
    if (typeof updateLanguage === "function") {
        updateLanguage();
    }
});

// Shared Components Loader (Footer, Buttons, Modals, Navbar)
function loadSharedComponents() {
    const navbarPlaceholder = document.getElementById('navbar-placeholder');
    if (navbarPlaceholder) {
        navbarPlaceholder.innerHTML = `
    <!-- Navbar -->
    <div class="navbar">
        <div class="logo">
            <a href="index.html#home">
                <img src="assets/icon/S__4620410_preview_rev_1.png" alt="Yarrapower Logo">
            </a>
        </div>

        <div class="hamburger" onclick="toggleMenu()">
            <span></span><span></span><span></span>
        </div>

        <div class="nav-items">
            <div class="menu">
                <a href="index.html#home" data-th="หน้าแรก" data-en="Home"><i class="lni lni-home"></i> <span
                        data-th="หน้าแรก" data-en="Home">Home</span></a>
                <a href="products.html" data-th="สินค้า" data-en="Products"><i class="lni lni-grid-alt"></i> <span
                        data-th="สินค้า" data-en="Products">Products</span></a>
                <a href="portfolio.html" data-th="ผลงาน" data-en="Portfolio"><i class="lni lni-briefcase"></i> <span
                        data-th="ผลงาน" data-en="Portfolio">Portfolio</span></a>
                <a href="contact.html" data-th="ติดต่อเรา" data-en="Contact Us"><i class="lni lni-phone"></i> <span
                        data-th="ติดต่อเรา" data-en="Contact Us">Contact Us</span></a>
                <button id="lang-toggle" class="lang-btn"><img src="https://flagcdn.com/w40/th.png" alt="TH">
                    TH</button>
            </div>

            <div class="nav-contact">
                <a href="tel:0814549191" class="nav-phone">
                    <i class="lni lni-phone"></i> 081-454-9191
                </a>
                <a href="tel:0819659495" class="nav-phone secondary">
                    <i class="lni lni-phone"></i> 081-965-9495
                </a>
            </div>
        </div>
    </div>
        `;

        // Set Active class based on current page
        const currentPath = window.location.pathname;
        const navLinks = navbarPlaceholder.querySelectorAll('.menu a');
        navLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (currentPath.endsWith(href) || (currentPath.endsWith('/') && href === 'index.html#home')) {
                link.classList.add('active');
            } else if (href.includes('#') && currentPath.includes(href.split('#')[0])) {
                // Special case for anchors on landing page
                if (currentPath.endsWith(href.split('#')[0]) || (currentPath.endsWith('/') && href.startsWith('index.html'))) {
                    link.classList.add('active');
                }
            }
        });

        // Re-attach language toggle listener since it was just injected
        const langBtn = document.getElementById('lang-toggle');
        if (langBtn) {
            langBtn.addEventListener('click', () => {
                currentLang = currentLang === 'th' ? 'en' : 'th';
                updateLanguage();
            });
        }
    }

    const sharedPlaceholder = document.getElementById('shared-components');
    if (!sharedPlaceholder) return;

    sharedPlaceholder.innerHTML = `
        <!-- Footer -->
        <div class="footer">
            <div class="footer-grid">
                <div class="footer-col">
                    <h4 data-th="เกี่ยวกับเรา" data-en="About Us">About Us</h4>
                    <p data-th="Yarra Power จัดจำหน่ายและให้บริการติดตั้งระบบพลังงานสะอาด เพื่อความยั่งยืนในการใช้พลังงานต่อทุกครัวเรือนและอุตสาหกรรม"
                        data-en="Yarra Power supplies and provides installation services for clean energy systems, promoting sustainable energy use for households and industrial sectors.">
                        Yarra Power supplies and provides installation services for clean energy systems, promoting
                        sustainable energy use for households and industrial sectors.</p>
                </div>

                <div class="footer-col">
                    <h4 data-th="บริการของเรา" data-en="Our Services">Our Services</h4>
                    <p data-th="ติดตั้งโซล่าเซลล์บ้าน" data-en="Home Solar Installation">Home Solar Installation</p>
                    <p data-th="ติดตั้งโซล่าเซลล์โรงงาน" data-en="Commercial Solar Installation">Commercial Solar Installation</p>
                    <p data-th="ระบบ Solar Roof" data-en="Solar Roof Systems">Solar Roof Systems</p>
                    <p data-th="บริการล้างแผง" data-en="Solar Panel Cleaning">Solar Panel Cleaning</p>
                    <p data-th="จัดจำหน่ายอุปกรณ์ระบบโซล่าเซลล์" data-en="Solar Equipment Distribution">
                        Solar Equipment Distribution
                    </p>
                </div>
                <div class="footer-col">
                    <h4 data-th="ติดต่อสอบถาม" data-en="Contact Us">Contact Us</h4>
                    <p data-th="โทร: 081-454-9191, 081-965-9495" data-en="Phone: 081-454-9191, 081-965-9495">Phone:
                        081-454-9191, 081-965-9495</p>
                    <p>Line: @yarrapower</p>
                    <p>Email: info@yarrapower.com</p>
                </div>
            </div>
            <p style="text-align: center; color: #6c757d; font-size: 14px; margin-top: 30px;" 
               data-th="© 2025 Yarrapower – All Rights Reserved"
               data-en="© 2025 Yarrapower – All Rights Reserved">© 2025 Yarrapower – All Rights Reserved </p>
        </div>

        <!-- Back to Top Button -->
        <button id="backToTop" onclick="scrollToTop()"><i class="lni lni-arrow-upward"></i></button>

        <!-- Floating Social Buttons -->
        <a href="https://line.me/ti/p/@yarrapower" target="_blank" class="line-float-btn">
            <i class="lni lni-line"></i>
        </a>
        <a href="https://www.facebook.com/profile.php?id=61574924817355" target="_blank" class="fb-float-btn"
            title="Facebook Yarrapower">
            <img src="https://upload.wikimedia.org/wikipedia/commons/0/05/Facebook_Logo_%282019%29.png" alt="Facebook Logo">
        </a>

        <!-- Product Modal -->
        <div id="product-modal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeProductModal()">&times;</span>
                <div class="product-modal-body">
                    <div class="product-modal-image">
                        <img id="modal-product-img" src="" alt="Product Image">
                    </div>
                    <div class="product-modal-info">
                        <h2 id="modal-product-name">Product Name</h2>
                        <p id="modal-product-desc" data-th="รายละเอียดสินค้าและข้อมูลทางเทคนิค"
                            data-en="Product details and technical specifications.">Product details and technical specifications.</p>
                        <div id="pdf-download-container"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lightbox Modal -->
        <div id="lightbox" class="lightbox">
            <span class="close" onclick="closeLightbox()">&times;</span>
            <div class="zoom-controls">
                <span class="zoom-btn" onclick="zoomImage(-0.1)"><i class="lni lni-minus"></i></span>
                <span id="zoom-level">100%</span>
                <span class="zoom-btn" onclick="zoomImage(0.1)"><i class="lni lni-plus"></i></span>
            </div>
            <img class="lightbox-content" id="lightbox-img">
            <a class="prev" onclick="changeSlide(-1)">&#10094;</a>
            <a class="next" onclick="changeSlide(1)">&#10095;</a>
        </div>
    `;

    // Ensure translation is applied to newly injected elements
    if (typeof updateLanguage === "function") {
        updateLanguage();
    }

    // Re-attach listeners since elements were just injected
    const lightbox = document.getElementById("lightbox");
    if (lightbox) {
        lightbox.addEventListener('click', (e) => {
            if (e.target.id === "lightbox") closeLightbox();
        });
    }

    // Escape key listener - registered once
    if (!window.hasEscListener) {
        document.addEventListener('keydown', (e) => {
            if (e.key === "Escape") {
                closeLightbox();
                closeProductModal();
            }
        });
        window.hasEscListener = true;
    }
}

// Portfolio Gallery_sup
const gallery = document.getElementById('portfolio-sup-gallery');

if (gallery) {
    loadLocalImages("assets/portfolio_sup", gallery, (images, container) => {
        images.forEach(file => {
            const img = document.createElement('img');
            img.src = file.download_url;
            img.alt = file.name;
            img.loading = "lazy";
            gallery.appendChild(img);
        });
        bindGalleryLightbox('portfolio-sup-gallery');
    });
}

// Partners Gallery
document.addEventListener('DOMContentLoaded', () => {
    const partnersGallery = document.getElementById('partners-gallery');
    if (!partnersGallery) return;

    loadLocalImages("assets/Partners", partnersGallery, (images, container) => {
        const fragment = document.createDocumentFragment();

        images.forEach(file => {
            const img = document.createElement('img');
            img.src = file.download_url;
            img.alt = file.name.replace(/\.[^/.]+$/, '');
            img.loading = "lazy";

            fragment.appendChild(img);
        });

        partnersGallery.appendChild(fragment);
        bindGalleryLightbox('partners-gallery');
    });
});


// Navbar Scroll Effect
let lastScrollTop = 0;
let isMenuOpen = false; // Flag to track menu state

window.addEventListener("scroll", function () {
    const navbar = document.querySelector(".navbar");
    let scrollTop = window.scrollY;

    // Smart Navbar Logic - Don't hide if menu is open
    if (isMenuOpen) {
        navbar.classList.remove("hidden");
        return;
    }

    // Scroll threshold to avoid flickering
    if (Math.abs(scrollTop - lastScrollTop) <= 5) return;

    if (scrollTop > lastScrollTop && scrollTop > 100) {
        navbar.classList.add("hidden"); // เลื่อนลง -> ซ่อน
    } else if (scrollTop < lastScrollTop) {
        navbar.classList.remove("hidden"); // เลื่อนขึ้น -> แสดง
    }
    lastScrollTop = scrollTop <= 0 ? 0 : scrollTop;

    if (scrollTop > 50) {
        navbar.classList.add("scrolled");
    } else {
        navbar.classList.remove("scrolled");
    }

    const backToTopBtn = document.getElementById("backToTop");
    if (scrollTop > 300) {
        backToTopBtn.classList.add("show");
    } else {
        backToTopBtn.classList.remove("show");
    }
});

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Gallery Scroll Reveal
const observerOptions = {
    threshold: 0.2
};
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('reveal');
            observer.unobserve(entry.target);
        }
    });
}, observerOptions);

// Generate Portfolio Images with Load More
const portfolioContainer = document.getElementById('portfolio-gallery');

if (portfolioContainer) {
    // Use local images instead of GitHub API
    const categories = [
        { path: "assets/portfolio/home", id: "home" },
        { path: "assets/portfolio/factory", id: "factory" }
    ];

    let allImages = [];

    categories.forEach(cat => {
        if (localImages[cat.path]) {
            const imgs = localImages[cat.path].map(name => ({
                name: name,
                download_url: `${cat.path}/${name}`,
                category: cat.id
            }));
            allImages = allImages.concat(imgs);
        }
    });

    // Sort by number
    allImages.sort((a, b) => {
        const numA = parseInt(a.name.match(/\d+/)) || 0;
        const numB = parseInt(b.name.match(/\d+/)) || 0;
        return numA - numB;
    });

    let displayedCount = 0;
    const itemsPerLoad = 6;

    function renderImages(count) {
        const fragment = document.createDocumentFragment();
        const imagesToRender = allImages.slice(displayedCount, displayedCount + count);

        imagesToRender.forEach(file => {
            const img = document.createElement('img');
            img.src = file.download_url;
            img.alt = file.name;
            img.loading = "lazy";
            img.dataset.category = file.category;
            img.className = 'gallery-item';

            observer.observe(img);
            fragment.appendChild(img);
        });

        portfolioContainer.appendChild(fragment);
        displayedCount += count;
    }

    function applyPortfolioFilter(filterValue) {
        const isPortfolioPage = window.location.pathname.includes('portfolio.html');
        const images = portfolioContainer.querySelectorAll('img');
        let visibleCount = 0;

        images.forEach(img => {
            const matchesFilter = filterValue === 'all' || img.dataset.category === filterValue;
            if (matchesFilter) {
                if (isPortfolioPage || visibleCount < 9) {
                    img.style.display = ''; // Show
                    visibleCount++;
                } else {
                    img.style.display = 'none'; // Over limit
                }
            } else {
                img.style.display = 'none'; // Wrong category
            }
        });
    }

    // Render all images initially to the DOM
    renderImages(allImages.length);

    // Apply initial filter (All) with 9-image limit if on Home
    applyPortfolioFilter('all');

    // Filter Logic
    const filterBtns = document.querySelectorAll('.filter-btn');
    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            // Active State
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const filterValue = btn.getAttribute('data-filter');
            applyPortfolioFilter(filterValue);
        });
    });

    // Use global lightbox binding
    bindGalleryLightbox('portfolio-gallery');
}

// Helper function to find a matching PDF robustly
function findMatchingPDF(imgSrc, productName) {
    if (!localPDFs || localPDFs.length === 0) return null;

    // Decode URL and get filename without extension
    const decodedUrl = decodeURIComponent(imgSrc);
    const filenameNoExt = decodedUrl.split('/').pop().replace(/\.[^/.]+$/, "").toLowerCase().trim().replace(/[_-]/g, ' ');
    const productNameLower = productName.toLowerCase().trim().replace(/[_-]/g, ' ');

    return localPDFs.find(pdf => {
        const pdfLower = pdf.toLowerCase().replace(/\.[^/.]+$/, "").trim().replace(/[_-]/g, ' ');
        return pdfLower === filenameNoExt ||
            pdfLower === productNameLower ||
            pdfLower.includes(productNameLower) ||
            productNameLower.includes(pdfLower) ||
            // Handle common model number matches (e.g. NEG19RC.20)
            (productNameLower.match(/neg\d+[a-z.]+/i) && pdfLower.includes(productNameLower.match(/neg\d+[a-z.]+/i)[0]));
    });
}

// Helper function to create product card
function createProductCard(file) {
    const productItem = document.createElement('div');
    productItem.className = 'product-item';

    const img = document.createElement('img');
    img.src = file.download_url;
    img.alt = file.name;
    img.loading = "lazy";
    observer.observe(img);

    const name = document.createElement('div');
    name.className = 'product-name';
    // Robustly remove extension and preserve internal dots/hyphens
    const lastDotIndex = file.name.lastIndexOf('.');
    const cleanName = (lastDotIndex !== -1 ? file.name.substring(0, lastDotIndex) : file.name)
        .replace(/_/g, ' '); // Only replace underscores with spaces
    name.textContent = cleanName;

    productItem.appendChild(img);
    productItem.appendChild(name);

    // Add PDF indicator if a matching PDF exists
    const matchingPDF = findMatchingPDF(file.download_url, cleanName);
    if (matchingPDF) {
        const pdfIndicator = document.createElement('div');
        pdfIndicator.className = 'pdf-indicator';
        pdfIndicator.innerHTML = '<i class="lni lni-download"></i>';
        productItem.appendChild(pdfIndicator);
        productItem.title = "Click to view datasheet";
    }

    return productItem;
}

// Generate Product Images
const productContainer = document.getElementById('product-gallery');
if (productContainer) {
    loadLocalImages("assets/products", productContainer, (images, container) => {
        const fragment = document.createDocumentFragment();
        images.forEach(file => {
            fragment.appendChild(createProductCard(file));
        });
        container.appendChild(fragment);
        bindGalleryLightbox('product-gallery');
    });
}

// Generate Longi Images
const longiContainer = document.getElementById('longi-gallery');
if (longiContainer) {
    loadLocalImages("assets/longi", longiContainer, (images, container) => {
        const fragment = document.createDocumentFragment();
        images.forEach(file => {
            fragment.appendChild(createProductCard(file));
        });
        container.appendChild(fragment);
        bindGalleryLightbox('longi-gallery');
    });
}

// Generate Trina Images
const trinaContainer = document.getElementById('trina-gallery');
if (trinaContainer) {
    loadLocalImages("assets/trina", trinaContainer, (images, container) => {
        const fragment = document.createDocumentFragment();
        images.forEach(file => {
            fragment.appendChild(createProductCard(file));
        });
        container.appendChild(fragment);
        bindGalleryLightbox('trina-gallery');
    });
}

// Hamburger Menu Toggle
function toggleMenu() {
    const navItems = document.querySelector('.nav-items');
    const hamburger = document.querySelector('.hamburger');
    navItems.classList.toggle('active');
    hamburger.classList.toggle('active');
    isMenuOpen = navItems.classList.contains('active'); // Update menu state
}

// Close menu when clicking a link
document.querySelectorAll('.nav-items a').forEach(link => {
    link.addEventListener('click', () => {
        const navItems = document.querySelector('.nav-items');
        if (navItems.classList.contains('active')) {
            navItems.classList.remove('active');
            document.querySelector('.hamburger').classList.remove('active');
            isMenuOpen = false; // Update menu state
        }
    });
});

// Counter Animation
function animateCounters() {
    const counters = document.querySelectorAll(".stat-number");
    counters.forEach(counter => {
        const text = counter.textContent;
        const numMatch = text.match(/(\d+)/);
        if (!numMatch) return;
        const target = parseInt(numMatch[1]);
        const prefix = text.substring(0, text.indexOf(numMatch[0]));
        const suffix = text.substring(text.indexOf(numMatch[0]) + numMatch[0].length);
        const duration = 2000;
        const step = target / (duration / 16);
        let current = 0;
        const timer = setInterval(() => {
            current += step;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            counter.textContent = prefix + Math.floor(current) + suffix;
        }, 16);
    });
}

// Language Switcher
let currentLang = localStorage.getItem('lang') || 'th';

function updateLanguage() {
    document.querySelectorAll('[data-th], [data-en]:not(#lang-toggle)').forEach(el => {
        if (el.hasAttribute('data-' + currentLang)) {
            el.textContent = el.getAttribute('data-' + currentLang);
        }
    });
    document.querySelectorAll('[data-placeholder-en]').forEach(el => {
        if (currentLang === 'en' && el.hasAttribute('data-placeholder-en')) {
            el.placeholder = el.getAttribute('data-placeholder-en');
        } else {
            // Reset to original placeholder (Thai)
            const original = el.getAttribute('placeholder');
            if (original) el.placeholder = original;
        }
    });

    const langBtn = document.getElementById('lang-toggle');
    if (langBtn) {
        langBtn.innerHTML = currentLang === 'th'
            ? '<img src="https://flagcdn.com/w40/th.png" alt="TH"> TH'
            : '<img src="https://flagcdn.com/w40/gb.png" alt="EN"> EN';
    }

    localStorage.setItem('lang', currentLang);
}

// document.getElementById('lang-toggle').addEventListener('click', () => {
//     currentLang = currentLang === 'th' ? 'en' : 'th';
//     updateLanguage();
// });
function autoSlide(containerId, speed = 0.5) {
    const container = document.getElementById(containerId);
    if (!container) return;

    let paused = false;

    container.addEventListener("mouseenter", () => paused = true);
    container.addEventListener("mouseleave", () => paused = false);

    function slide() {
        if (!paused && container.scrollWidth > container.clientWidth) {
            container.scrollLeft += speed;

            if (container.scrollLeft + container.clientWidth >= container.scrollWidth - 1) {
                container.scrollLeft = 0;
            }
        }
        requestAnimationFrame(slide);
    }

    slide();
}

let currentImages = [];
let currentIndex = 0;
let currentZoom = 1;

function zoomImage(step) {
    currentZoom += step;
    if (currentZoom < 0.5) currentZoom = 0.5; // จำกัดซูมออกต่ำสุด
    if (currentZoom > 3) currentZoom = 3;     // จำกัดซูมเข้าสูงสุด

    const img = document.getElementById("lightbox-img");
    if (img) img.style.transform = `scale(${currentZoom})`;

    const zoomLevelText = document.getElementById("zoom-level");
    if (zoomLevelText) {
        zoomLevelText.innerText = `${Math.round(currentZoom * 100)}%`;
    }
}

function openLightbox(images, index) {
    currentImages = images;
    currentIndex = index;
    currentZoom = 1; // รีเซ็ตค่าซูม
    document.getElementById("lightbox").style.display = "flex";
    const img = document.getElementById("lightbox-img");
    img.src = currentImages[currentIndex].src;
    img.style.transform = "scale(1)"; // รีเซ็ตขนาดภาพ

    // Reset Zoom Text
    const zoomLevelText = document.getElementById("zoom-level");
    if (zoomLevelText) zoomLevelText.innerText = "100%";

    document.body.style.overflow = "hidden"; // Disable scroll
}

function openProductModal(imgSrc, productName) {
    const modal = document.getElementById("product-modal");
    const modalImg = document.getElementById("modal-product-img");
    const modalName = document.getElementById("modal-product-name");
    const pdfContainer = document.getElementById("pdf-download-container");

    modalImg.src = imgSrc;
    modalName.textContent = productName;
    pdfContainer.innerHTML = '';

    // Check for matching PDF using robust helper
    const matchingPDF = findMatchingPDF(imgSrc, productName);

    if (matchingPDF) {
        const downloadBtn = document.createElement('a');
        downloadBtn.href = `assets/pdf/${matchingPDF}`;
        downloadBtn.className = 'btn-download';
        downloadBtn.target = '_blank';
        downloadBtn.innerHTML = '<i class="lni lni-download"></i> Download Datasheet (PDF)';
        pdfContainer.appendChild(downloadBtn);
    }

    modal.style.display = "flex";
    document.body.style.overflow = "hidden";
}

function closeProductModal() {
    const modal = document.getElementById("product-modal");
    if (modal) modal.style.display = "none";
    document.body.style.overflow = "auto";
}

function closeLightbox() {
    const lb = document.getElementById("lightbox");
    if (lb) lb.style.display = "none";
    document.body.style.overflow = "auto"; // Enable scroll
}

function changeSlide(step) {
    currentIndex += step;

    if (currentIndex < 0) currentIndex = currentImages.length - 1;
    if (currentIndex >= currentImages.length) currentIndex = 0;

    currentZoom = 1; // รีเซ็ตค่าซูมเมื่อเปลี่ยนรูป
    const img = document.getElementById("lightbox-img");
    img.src = currentImages[currentIndex].src;
    img.style.transform = "scale(1)";

    // Reset Zoom Text
    const zoomLevelText = document.getElementById("zoom-level");
    if (zoomLevelText) zoomLevelText.innerText = "100%";
}



function bindGalleryLightbox(galleryId) {
    const gallery = document.getElementById(galleryId);
    if (!gallery) return;

    gallery.addEventListener("click", (e) => {
        if (e.target.tagName !== "IMG") return;

        // Select only visible images
        const images = Array.from(gallery.querySelectorAll(".product-item img, img")).filter(img => img.offsetParent !== null);
        const index = images.indexOf(e.target);

        if (index !== -1) {
            // For product-gallery, open modal instead of lightbox
            if (galleryId.includes('gallery') && !galleryId.includes('portfolio') && !galleryId.includes('partners')) {
                const productItem = e.target.closest('.product-item');
                const name = productItem ? productItem.querySelector('.product-name').textContent : e.target.alt;
                openProductModal(e.target.src, name);
            } else {
                openLightbox(images, index);
            }
        }
    });
}


function loadPartnersGallery(images) {
    const gallery = document.getElementById("partners-gallery");
    if (!gallery) return;

    images.forEach(src => {
        const img = document.createElement("img");
        img.src = src;
        gallery.appendChild(img);
    });

}

// Clear Cache and Unregister Service Worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(function (registrations) {
        for (let registration of registrations) {
            registration.unregister();
        }
    });
}

if ('caches' in window) {
    caches.keys().then((names) => {
        names.forEach((name) => {
            caches.delete(name);
        });
    });
    console.log("All caches cleared.");
}

// Contact Form Submission Handler
document.addEventListener('DOMContentLoaded', () => {
    const contactForm = document.getElementById('contact-form');
    const formStatus = document.getElementById('form-status');

    if (contactForm) {
        contactForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Use the Google Apps Script Web App URL from your deployment
            // REPLACE THIS URL with your actual deployment URL
            const SCRIPT_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vQp97zoFy-TR5qgsV0GKssY4WnDNP-MsQYBOOywyCd7z2jpAXOInf3iU0WdPJGchucS8HOtWdo5MqVA/pubhtml';

            const submitBtn = contactForm.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.textContent;

            // Show loading state
            submitBtn.disabled = true;
            submitBtn.textContent = currentLang === 'th' ? 'กำลังส่งข้อมูล...' : 'Sending...';
            formStatus.style.display = 'block';
            formStatus.style.color = '#4b5563';
            formStatus.textContent = currentLang === 'th' ? 'กำลังประมวลผล...' : 'Processing...';

            try {
                const formData = new FormData(contactForm);
                const response = await fetch(SCRIPT_URL, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.result === 'success') {
                    // Success
                    formStatus.style.color = '#059669';
                    formStatus.textContent = currentLang === 'th'
                        ? 'ส่งข้อมูลสำเร็จ ขอบคุณที่ติดต่อเรา!'
                        : 'Message sent successfully! Thank you for contacting us.';
                    contactForm.reset();
                } else {
                    throw new Error(result.error || 'Submission failed');
                }
            } catch (error) {
                console.error('Error submitting form:', error);

                // If SCRIPT_URL is placeholder, give a specific message
                if (SCRIPT_URL.includes('URL_HERE')) {
                    formStatus.style.color = '#ef4444';
                    formStatus.textContent = currentLang === 'th'
                        ? 'กรุณาใส่ Web App URL ในไฟล์ main.js ก่อนใช้งาน'
                        : 'Please set the Web App URL in main.js before use.';
                } else {
                    formStatus.style.color = '#ef4444';
                    formStatus.textContent = currentLang === 'th'
                        ? 'เกิดข้อผิดพลาดในการส่งข้อมูล กรุณาลองใหม่อีกครั้ง'
                        : 'Error sending message. Please try again later.';
                }
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalBtnText;
            }
        });
    }
});
