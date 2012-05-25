package lex.book
{
	
	import flash.display.*;
	import flash.events.*;
	import flash.geom.*;
	import lex.book.AnimationConer;
	import lex.book.BookStyle;
	import lex.book.DropPage;
	import lex.book.GlareShade;
	import lex.book.LinkPageAnimation;
	import lex.book.MouseControl;
	import lex.book.PageNavigator;
	import lex.book.Slowing;
	import lex.geom.V2;
	/**
	 * ...
	 * @author Lexcuk
	 */
	public class PageFlip extends Sprite
	{
		private var s:BookStyle;
		private var dropPage:DropPage;
		
		
		private var bottomContent:MovieClip;
		private var doubleContent:MovieClip;
		private var dropContent:MovieClip;
		private var nav:PageNavigator;
		private var gsp:Sprite;
		
		private var slowing:Slowing;
		
		private var mouseControl:MouseControl;
		private var animaCone:AnimationConer;
		private var linkAnima:LinkPageAnimation;
		//private var gotoSp:Sprite;
		private var lastGotoInd:int;
		private var gotoSpArr:Array = [];
		public var eventComment:String = '';
		
		private var delayMc:MovieClip = new MovieClip();
		
		public function PageFlip(contentClass:Class, sizeX:int, sizeY:int) 
		{
			
			bottomContent = new contentClass;
			
			
			doubleContent = new contentClass;
			
			
			dropContent = new contentClass;
			
			nav = new PageNavigator();
		
			//trace('hello');
			s = new BookStyle(sizeX, sizeY, nav);
			
			dropPage = new DropPage(s);
			
			addChild(dropPage.masterSp);
			
			dropPage.masterSp.name = 'dropPageMasterSp'
			
			dropPage.masterSp.x = sizeX;
			dropPage.masterSp.y = 0;
			
			dropPage.resetContentBottom(bottomContent);
			//bottomContent.alpha = 0.5;
			bottomContent.gotoAndStop(1);
			
			dropPage.resetContentDouble(doubleContent);
			//doubleContent.alpha = 0.5;
			doubleContent.gotoAndStop(2);
			
			dropPage.resetContentDrop(dropContent);
			//dropContent.alpha = 0.5;
			dropContent.gotoAndStop(1);
			
			//dropPage.action(dropPage.masterSp.mouseX, dropPage.masterSp.mouseY);
			
			
			//dropPage.masterSp.addChild(
			//gotoSp = new Sprite();
			//gotoSp.buttonMode = true;
			lastGotoInd = 100;
			
			pageGoto(1, 1);
			
			slowing = new Slowing(s.width/10);
			slowing.callBack = slowingCall;
			
			mouseControl = new MouseControl(dropPage, nav);
			mouseControl.addControl();
			mouseControl.callBackContentRestoreAction = navRestoreContentMouseControl;
			nav.cur = 1;
			nav.max = bottomContent.totalFrames;
			
			animaCone = new AnimationConer(dropPage,nav);
			dropPage.masterSp.addChild(animaCone.twoRectSp);
			animaCone.callBackContentRestoreAction = animaConeNavRestoreContent;
			animaCone.callBackRevisionMouseControl = forceMouseControl;
			
			
			linkAnima = new LinkPageAnimation(dropPage, nav);
			linkAnima.callBackContentRestoreAction = linkAnimationRestoreContent;
			//linkAnima.animatePageTo(1);
			
			//dropPage.masterSp.addChild(gotoSp);
			
			//gotoSp.buttonMode = true;
			
			addEventListener(Event.ENTER_FRAME, buttonModeLinkEnterFrameHandler);
			
			dropPage.masterSp.addEventListener(MouseEvent.CLICK, dropPageClickHandler);
			
			dropPage.leftTr = false;
			nav.leftTr = false;
			dropPage.action(s.width, s.height);
			
			//mouseControl.forceMouseDown();
		}

		private function isNumber(str:String):Boolean{
			var reg:RegExp = /\d*/;
			if (reg.exec(str).toString().length == str.length) return true;
			return false;
		}

		private function dropPageClickHandler(e:MouseEvent):void {
			var ind:int;
			var str:String = e.target.name;
			var linkString:String = '';
			if (str.indexOf('goto', 0) == 0) {
				linkString = str.substr(5, str.length);
				//trace('linkString '+linkString);
				if (!isNumber(linkString)) {
					eventComment = linkString;
					dispatchEvent(new Event(Event.CHANGE));
					return;
				}
				ind = parseInt(str.substr(5, str.length));
				
				//ind = 2;
				
				//trace('LINK ACTION ' + ind);
				nav.testAction(ind);
				
				nav.action(nav.cur, nav.aim);
				
				linkAnima.animatePageTo(nav.aim);
				
			}
			
		}

		public function buttonModeLinkEnterFrameHandler(e:Event):void {
			gotoButtonMode(doubleContent);
			gotoButtonMode(bottomContent);
			gotoButtonMode(dropContent);
			
			//if (this.contains(gotoSp)) removeChild(gotoSp);
			removeEventListener(Event.ENTER_FRAME, buttonModeLinkEnterFrameHandler);
		}






		private function gotoButtonMode(sp:Sprite):void {
			var i:int;
			var remArr:Array = [];
			for (i = 0; i < sp.numChildren; i++) {
				if (sp.getChildAt(i) != null) {
					if (sp.getChildAt(i).name.indexOf('goto', 0) == 0) {
						(sp.getChildAt(i) as Sprite).buttonMode = true;
						
						//sp.removeChild(sp.getChildAt(i));
					}
				}
			}
			for (i = 0; i < remArr.length; i++) remArr[i].parent.removeChild(remArr[i]);
		}

		private function forceMouseControl():void {
			mouseControl.forceMouseDown(animaCone.curAniV2);
		}

		private function linkAnimationRestoreContent():void {
			navRestoreContent();
			mouseControl.block = true;
			if (dropPage.masterSp.contains(animaCone.twoRectSp)) dropPage.masterSp.removeChild(animaCone.twoRectSp);
			if (!linkAnima.imBusy) {
				mouseControl.block = false;
				dropPage.masterSp.addChild(animaCone.twoRectSp);
			}
		}

		private function animaConeNavRestoreContent():void {
			navRestoreContent();
			mouseControl.block = true;
			if (dropPage.masterSp.contains(animaCone.twoRectSp)) dropPage.masterSp.removeChild(animaCone.twoRectSp);
			if (!animaCone.imBusy) {
				dropPage.masterSp.addChild(animaCone.twoRectSp);
				mouseControl.block = false;
			}
		}


		private function navRestoreContentMouseControl():void {
			//navRestoreContent();
			pageGoto(nav.cur, nav.aim);
			
			if (dropPage.masterSp.contains(animaCone.twoRectSp)) dropPage.masterSp.removeChild(animaCone.twoRectSp);
			if (!mouseControl.imBusy) dropPage.masterSp.addChild(animaCone.twoRectSp);
		}

		private function navRestoreContent():void {
			pageGoto(nav.cur, nav.aim);
			//trace(nav.cur + ' ' + nav.aim);
		}

		private function pageGoto(current:int, target:int):void {
			nav.action(current, target);
			bottomContent.gotoAndStop(nav.bottom);
			
			doubleContent.gotoAndStop(nav.double);
			
			dropContent.gotoAndStop(nav.drop);
			
			
			
			
			dropPage.leftTr = nav.leftTr;
			
			gotoButtonMode(doubleContent);
			gotoButtonMode(bottomContent);
			gotoButtonMode(dropContent);
			
			if (dropPage.masterSp.mouseX > 0) 
			dropPage.action(s.width, s.height);
			
			if (dropPage.masterSp.mouseX < 0) {
				//if (nav.cur != nav.max) 
				dropPage.action( -s.width, s.height);
				//if (nav.cur == nav.max) dropPage.lastPageAction();
				//trace('lastPageAction');
			}
			
			if (nav.cur == nav.max) {
				dropPage.lastPageAction();
				//trace('lastPageAction');
			}
			
			addEventListener(Event.ENTER_FRAME, buttonModeLinkEnterFrameHandler);
			//fillGotoSp();
		}

		private function sandwichMouseMoveHandler(e:MouseEvent):void {
			if (!mouseControl.imBusy) {
				var coord:V2 = dropPage.fixMouse.fix(new V2(dropPage.masterSp.mouseX, dropPage.masterSp.mouseY));
				slowing.setCoord(coord.x, coord.y);
			}
			
		}

		private function slowingCall():void {
			dropPage.action(slowing.curV2.x, slowing.curV2.y);
		}

	}

}