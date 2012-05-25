package
{
	
	import flash.display.*;
	import flash.events.*;
	import lex.book.*;
	import flash.net.*

	
	/**
	 * ...
	 * @author Lexcuk
	 */
	public class Doc extends Sprite
	{
		private var pageFlip:PageFlip;
		public function Doc() 
		{
			addChild(pageFlip = new PageFlip(ContentMc, 400, 600));
			pageFlip.addEventListener(Event.CHANGE, pageFlipChangeHandler);
			pageFlip.x = 0;
			pageFlip.y = 0;
			
		}

		private function pageFlipChangeHandler(e:Event):void {
			if (pageFlip.eventComment == 'kirupa') navigateToURL(new URLRequest("http://www.kirupa.com"), "_blank");
			if (pageFlip.eventComment == 'game') navigateToURL(new URLRequest("http://www.kongregate.com/games/Lexcuk/rat-rods-ralli"), "_blank");
		}

		
	}

}