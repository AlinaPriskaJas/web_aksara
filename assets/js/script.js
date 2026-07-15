// Smooth Scroll
document.querySelectorAll('a[href^="#"]').forEach(anchor => {

    anchor.addEventListener('click', function(e) {

        e.preventDefault();

        document.querySelector(this.getAttribute('href')).scrollIntoView({
            behavior: 'smooth'
        });

    });

});

// Navbar Shadow Saat Scroll
window.addEventListener("scroll", function(){

    const navbar = document.querySelector(".navbar");

    if(window.scrollY > 20){

        navbar.style.boxShadow = "0 5px 20px rgba(0,0,0,.12)";

    }else{

        navbar.style.boxShadow = "0 3px 10px rgba(0,0,0,.08)";

    }

});


/*=========================================
=            SLIDER PREVIEW HERO
=========================================*/

(function () {

    const slides = document.querySelectorAll(".preview-slide");

    if (slides.length <= 1) return;

    let current = 0;

    function showSlide(index){

        slides[current].classList.remove("active");

        // restart animasi zoom
        slides[index].style.animation = "none";
        void slides[index].offsetWidth;
        slides[index].style.animation = "";

        current = index;

        slides[current].classList.add("active");

    }

    setInterval(function(){

        let next = current + 1;

        if(next >= slides.length){
            next = 0;
        }

        showSlide(next);

    },5000);

})();